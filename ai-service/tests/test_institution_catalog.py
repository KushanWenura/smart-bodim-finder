import importlib.util
import pathlib


AI_ROOT = pathlib.Path(__file__).parents[1]
PROJECT_ROOT = AI_ROOT.parent


def load_module(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, AI_ROOT / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


catalog_generator = load_module("institution_catalog", "generate_institution_catalog.py")
domain_generator = load_module("domain_dataset", "generate_domain_dataset.py")


def test_catalog_covers_nationwide_branch_networks():
    rows = catalog_generator.build()
    campuses = [row for row in rows if row["type"] == "campus"]
    organizations = {row["organization"] for row in campuses}

    assert len(rows) == 160
    assert len(campuses) == 152
    assert len(organizations) == 41
    assert sum(row["organization"] == "ESOFT Metro Campus" for row in campuses) == 38
    assert sum(row["organization"] == "Open University of Sri Lanka" for row in campuses) == 27
    assert all(row["sourceUrl"].startswith("https://") for row in rows)


def test_domain_dataset_uses_every_catalog_destination():
    catalog = PROJECT_ROOT / "datasets/catalog/sri_lanka_higher_education_destinations.json"
    rows = domain_generator.generate(catalog)

    assert len(rows) == 7680
    assert len({row["groupId"] for row in rows}) == 1920
    assert len({row["destination"] for row in rows}) == 160
    assert all(row["destinationSource"].startswith("https://") for row in rows)

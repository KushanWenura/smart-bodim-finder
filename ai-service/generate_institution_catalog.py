"""Build the reproducible Sri Lankan higher-education destination catalog.

Scope: UGC universities/campuses, nationwide public university centres, and
Ministry-approved non-state institutes. Physical branches published by each
institution are separate destinations; online-only centres are excluded.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parents[1]
UGC = "https://www.ugc.ac.lk/index.php?Itemid=10&id=14&lang=en&option=com_university&view=single"
UGC_CAMPUSES = "https://www.ugc.ac.lk/index.php?Itemid=25&lang=en&option=com_university&view=campuses"
MOHE = "https://www.mohe.gov.lk/index.php?Itemid=225&id=345&lang=en&option=com_content&view=article"
OUSL = "https://ou.ac.lk/res/"
OCEAN = "https://ocu.ac.lk/history/"
ICBT = "https://icbt.lk/branches/"
SLIIT = "https://www.sliit.lk/about/campuses-centers/"
NIBM = "https://nibm.ac.lk/contact"
ESOFT = "https://esoft.lk/about-us/"
CINEC = "https://www.cinec.edu/about-cinec.html"

TOWNS = {
    "Addalaichenai": (7.2552, 81.8568), "Ambalangoda": (6.2442, 80.0590), "Ambalantota": (6.1241, 81.1185),
    "Ampara": (7.2917, 81.6720), "Anuradhapura": (8.3114, 80.4037), "Avissawella": (6.9553, 80.2114),
    "Badulla": (6.9934, 81.0550), "Bambalapitiya": (6.8915, 79.8568), "Bandarawela": (6.8294, 80.9900),
    "Battaramulla": (6.9022, 79.9198), "Batticaloa": (7.7170, 81.7000), "Belihuloya": (6.7150, 80.7870),
    "Boossa": (6.0660, 80.1640), "Borella": (6.9147, 79.8777), "Chilaw": (7.5758, 79.7953),
    "Colombo": (6.9271, 79.8612), "Colombo Fort": (6.9344, 79.8428), "Dambulla": (7.8742, 80.6511),
    "Dehiwala": (6.8516, 79.8653), "Embilipitiya": (6.3439, 80.8489), "Galle": (6.0329, 80.2168),
    "Gampaha": (7.0912, 79.9983), "Hatton": (6.8916, 80.5955), "Homagama": (6.8412, 80.0030),
    "Horana": (6.7159, 80.0626), "Jaffna": (9.6615, 80.0255), "Ja-Ela": (7.0744, 79.8919),
    "Kalutara": (6.5854, 79.9607), "Kandy": (7.2906, 80.6337), "Karapitiya": (6.0662, 80.2272),
    "Katubedda": (6.7969, 79.9018), "Kegalle": (7.2513, 80.3464), "Kelaniya": (6.9749, 79.9159),
    "Kilinochchi": (9.3803, 80.3770), "Kiribathgoda": (6.9785, 79.9270), "Kirulapone": (6.8777, 79.8748),
    "Kuliyapitiya": (7.4680, 80.0401), "Kurunegala": (7.4863, 80.3652), "Malabe": (6.9041, 79.9546),
    "Mannar": (8.9810, 79.9044), "Maradana": (6.9270, 79.8640), "Matale": (7.4675, 80.6234),
    "Matara": (5.9549, 80.5550), "Mihintale": (8.3500, 80.5030), "Monaragala": (6.8728, 81.3507),
    "Moratuwa": (6.7730, 79.8816), "Mullaitivu": (9.2671, 80.8142), "Narammala": (7.4317, 80.2150),
    "Nawala": (6.8935, 79.8868), "Negombo": (7.2083, 79.8358), "Nittambuwa": (7.1442, 80.0967),
    "Nugegoda": (6.8649, 79.8997), "Oluvil": (7.2833, 81.8588), "Padukka": (6.8408, 80.0903),
    "Panadura": (6.7132, 79.9026), "Peradeniya": (7.2541, 80.5974), "Piliyandala": (6.8018, 79.9227),
    "Polonnaruwa": (7.9403, 81.0188), "Puttalam": (8.0362, 79.8283), "Ragama": (7.0302, 79.9170),
    "Rajagiriya": (6.9094, 79.8943), "Ratmalana": (6.8211, 79.8862), "Ratnapura": (6.7056, 80.3847),
    "Sammanthurai": (7.3620, 81.8020), "Saliyapura": (8.4140, 80.4030), "Sooriyawewa": (6.3020, 81.0010),
    "Tangalle": (6.0243, 80.7941), "Trincomalee": (8.5874, 81.2152), "Vavuniya": (8.7514, 80.4971),
    "Wattala": (6.9890, 79.8916), "Welisara": (7.0281, 79.9074), "Wennappuwa": (7.3437, 79.8417),
    "Wellamadama": (5.9383, 80.5763), "Yakkala": (7.0899, 80.0365), "Makandura": (7.3220, 79.9890),
    "Hapugala": (6.0780, 80.1910), "Mapalana": (6.0560, 80.5610), "Pambaimadu": (8.7590, 80.4970),
    "Vantharumoolai": (7.7970, 81.5790), "Nawala-Nugegoda": (6.8935, 79.8868), "Colombo 07": (6.9105, 79.8611),
}


def normalize_alias(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", " ", value.casefold()).strip()


def build() -> list[dict]:
    rows: list[dict] = []

    def add(organization: str, branch: str | None, town: str, aliases: list[str] | None = None,
            source: str = UGC, sector: str = "public", destination_type: str = "campus") -> None:
        latitude, longitude = TOWNS[town]
        name = organization if not branch else f"{organization} - {branch}"
        generated_aliases = [organization, name]
        if branch:
            generated_aliases.extend([f"{organization} {branch}", branch])
        generated_aliases.extend(aliases or [])
        rows.append({
            "name": name, "organization": organization, "branch": branch,
            "type": destination_type, "sector": sector, "town": town,
            "latitude": latitude, "longitude": longitude,
            "locationPrecision": "town-reference",
            "aliases": sorted({normalize_alias(item) for item in generated_aliases if item}),
            "sourceUrl": source,
            "recognitionSourceUrl": MOHE if sector == "non-state" else UGC,
        })

    # Universities and attached campuses under the UGC.
    public_sites = [
        ("University of Colombo", "Main Campus", "Colombo", ["uoc", "colombo university"]),
        ("University of Colombo", "Sri Palee Campus", "Horana", ["sri pali campus"]),
        ("University of Colombo", "School of Computing", "Colombo", ["ucsc"]),
        ("University of Peradeniya", None, "Peradeniya", ["uop", "peradeniya university"]),
        ("University of Sri Jayewardenepura", "Gangodawila", "Nugegoda", ["usj", "japura university"]),
        ("University of Sri Jayewardenepura", "Technology Faculty Pitipana", "Homagama", ["usj technology"]),
        ("University of Kelaniya", "Main Campus", "Kelaniya", ["uok", "kelaniya university"]),
        ("University of Kelaniya", "Faculty of Medicine Ragama", "Ragama", ["kelaniya medical faculty"]),
        ("University of Moratuwa", "Katubedda", "Katubedda", ["uom", "moratuwa university"]),
        ("University of Moratuwa", "Institute of Technology Diyagama", "Homagama", ["itum", "diyagama campus"]),
        ("University of Jaffna", "Main Campus", "Jaffna", ["uoj", "jaffna university"]),
        ("University of Jaffna", "Ariviyal Nagar Campus", "Kilinochchi", ["jaffna kilinochchi campus"]),
        ("University of Ruhuna", "Wellamadama Campus", "Wellamadama", ["uor", "ruhuna university"]),
        ("University of Ruhuna", "Engineering Faculty Hapugala", "Hapugala", ["ruhuna engineering"]),
        ("University of Ruhuna", "Medicine and Allied Health Karapitiya", "Karapitiya", ["ruhuna medical faculty"]),
        ("University of Ruhuna", "Agriculture and Technology Mapalana", "Mapalana", ["ruhuna agriculture"]),
        ("Eastern University Sri Lanka", "Vantharumoolai Campus", "Vantharumoolai", ["eusl", "eastern university"]),
        ("Eastern University Sri Lanka", "Trincomalee Campus", "Trincomalee", ["eastern university trinco"]),
        ("Eastern University Sri Lanka", "Swamy Vipulananda Institute", "Batticaloa", ["svias"]),
        ("South Eastern University of Sri Lanka", "Oluvil Campus", "Oluvil", ["seusl", "south eastern university"]),
        ("South Eastern University of Sri Lanka", "Applied Sciences Sammanthurai", "Sammanthurai", ["seusl science"]),
        ("Rajarata University of Sri Lanka", "Mihintale Campus", "Mihintale", ["rusl", "rajarata university"]),
        ("Rajarata University of Sri Lanka", "Medicine Saliyapura", "Saliyapura", ["rajarata medical faculty"]),
        ("Sabaragamuwa University of Sri Lanka", None, "Belihuloya", ["susl", "sabaragamuwa university"]),
        ("Wayamba University of Sri Lanka", "Kuliyapitiya Premises", "Kuliyapitiya", ["wusl", "wayamba university"]),
        ("Wayamba University of Sri Lanka", "Makandura Premises", "Makandura", ["wayamba makandura"]),
        ("Uva Wellassa University of Sri Lanka", None, "Badulla", ["uwu", "uva wellassa"]),
        ("University of the Visual and Performing Arts", None, "Colombo", ["uvpa"]),
        ("Gampaha Wickramarachchi University of Indigenous Medicine", None, "Yakkala", ["gwuim"]),
        ("University of Vavuniya Sri Lanka", "Pambaimadu Campus", "Pambaimadu", ["uov", "vavuniya university"]),
    ]
    for organization, branch, town, aliases in public_sites:
        add(organization, branch, town, aliases, UGC_CAMPUSES if "Campus" in (branch or "") else UGC)

    # Open University: all 9 regional and 18 physical study centres listed by OUSL.
    for town in ["Anuradhapura", "Badulla", "Batticaloa", "Nawala-Nugegoda", "Jaffna", "Kandy", "Kurunegala", "Matara", "Ratnapura"]:
        label = "Colombo Regional Centre (Nawala)" if town == "Nawala-Nugegoda" else f"{town} Regional Centre"
        add("Open University of Sri Lanka", label, town, ["ousl", f"open university {town}"], OUSL)
    for town in ["Ambalangoda", "Ambalantota", "Ampara", "Bandarawela", "Galle", "Gampaha", "Hatton", "Kalutara", "Kegalle", "Kilinochchi", "Mannar", "Matale", "Monaragala", "Mullaitivu", "Polonnaruwa", "Puttalam", "Trincomalee", "Vavuniya"]:
        add("Open University of Sri Lanka", f"{town} Study Centre", town, ["ousl", f"open university {town}"], OUSL)

    # Public universities established outside the main UGC university list.
    add("General Sir John Kotelawala Defence University", "Ratmalana Campus", "Ratmalana", ["kdu", "kotelawala university"], "https://kdu.ac.lk/")
    add("General Sir John Kotelawala Defence University", "Southern Campus Sooriyawewa", "Sooriyawewa", ["kdu southern campus"], "https://kdu.ac.lk/")
    for town in ["Colombo", "Jaffna", "Trincomalee", "Batticaloa", "Tangalle", "Boossa", "Panadura", "Negombo"]:
        branch = "Main Campus Mattakkuliya" if town == "Colombo" else f"{town} Regional Centre"
        add("Ocean University of Sri Lanka", branch, town, ["ocu", f"ocean university {town}"], OCEAN)
    add("University of Vocational Technology", None, "Ratmalana", ["univotec", "uovt"], "https://uovt.ac.lk/")
    add("Buddhist and Pali University of Sri Lanka", None, "Homagama", ["bpu"], "https://www.bpu.ac.lk/")
    add("Bhiksu University of Sri Lanka", None, "Anuradhapura", ["busl"], "https://busl.ac.lk/")

    # Ministry-approved non-state degree-awarding institutes and their published branch networks.
    for branch, town in [("Malabe Campus", "Malabe"), ("Metro Campus Colombo", "Colombo"), ("City Uni Colombo", "Colombo"),
                         ("Matara Centre", "Matara"), ("Kandy Uni", "Kandy"), ("Kurunegala Centre", "Kurunegala"),
                         ("Northern Uni Jaffna", "Jaffna"), ("SLIIT International Colombo", "Colombo")]:
        add("Sri Lanka Institute of Information Technology", branch, town, ["sliit", f"sliit {town}"], SLIIT, "non-state")
    add("NSBM Green University", None, "Homagama", ["nsbm", "nsbm green university"], "https://www.nsbm.ac.lk/", "non-state")
    for branch, town in [("Malabe Campus", "Malabe"), ("Nugegoda Branch", "Nugegoda"), ("Jaffna Branch", "Jaffna"), ("Trincomalee Branch", "Trincomalee")]:
        add("CINEC Campus", branch, town, ["cinec", f"cinec {town}"], CINEC, "non-state")
    for organization, town, aliases, source in [
        ("Sri Lanka International Buddhist Academy", "Kandy", ["siba"], "https://siba.edu.lk/"),
        ("Institute of Chartered Accountants of Sri Lanka", "Colombo", ["ca sri lanka", "icasl"], "https://www.casrilanka.com/"),
        ("SANASA Campus", "Kegalle", ["sanasa university"], "https://www.sanasacampus.lk/"),
        ("Horizon Campus", "Malabe", ["horizon university"], "https://horizoncampus.edu.lk/"),
        ("KIU Campus", "Battaramulla", ["kiu university"], "https://www.kiu.ac.lk/"),
        ("Sri Lanka Technological Campus", "Padukka", ["sltc", "slt campus"], "https://sltc.ac.lk/"),
        ("SAEGIS Campus", "Nugegoda", ["saegis"], "https://saegis.ac.lk/"),
        ("Aquinas College of Higher Studies", "Borella", ["aquinas campus"], "https://www.aquinas.lk/"),
        ("Institute of Chemistry Ceylon", "Rajagiriya", ["ichem", "college of chemical sciences"], "https://ichemc.edu.lk/"),
        ("Benedict XVI Catholic Institute", "Negombo", ["bci campus"], "https://www.bci.lk/"),
        ("Royal Institute Colombo", "Colombo", ["ric"], "https://ric.lk/"),
        ("Business Management School", "Colombo", ["bms campus"], "https://www.bms.lk/"),
        ("International Institute of Health Sciences", "Welisara", ["iihs"], "https://iihsciences.edu.lk/"),
    ]:
        add(organization, None, town, aliases, source, "non-state")

    for town in ["Colombo", "Kandy", "Galle", "Nugegoda", "Batticaloa", "Matara", "Jaffna", "Kurunegala", "Gampaha", "Anuradhapura"]:
        add("ICBT Campus", town, town, ["icbt", f"icbt {town}", "icbt southern campus" if town == "Matara" else ""], ICBT, "non-state")

    esoft_towns = ["Bambalapitiya", "Kandy", "Gampaha", "Negombo", "Nugegoda", "Kurunegala", "Ampara", "Anuradhapura", "Avissawella", "Badulla", "Bandarawela", "Batticaloa", "Chilaw", "Dambulla", "Galle", "Embilipitiya", "Hatton", "Ja-Ela", "Jaffna", "Kalutara", "Katubedda", "Kegalle", "Kiribathgoda", "Kuliyapitiya", "Matale", "Matara", "Monaragala", "Narammala", "Nittambuwa", "Panadura", "Piliyandala", "Polonnaruwa", "Ratnapura", "Tangalle", "Trincomalee", "Wattala", "Wennappuwa", "Colombo Fort"]
    for town in esoft_towns:
        label = "Campus One - One Galle Face" if town == "Colombo Fort" else town
        add("ESOFT Metro Campus", label, town, ["esoft", f"esoft {town}"], ESOFT, "non-state")

    # NIBM is a public statutory higher-education network under the Ministry.
    for branch, town in [("Colombo Campus", "Colombo 07"), ("Rajagiriya Campus", "Rajagiriya"), ("Kandy Campus", "Kandy"),
                         ("Kurunegala Campus", "Kurunegala"), ("Galle Campus", "Galle"), ("Matara Campus", "Matara"),
                         ("National Innovation Centre Kirulapone", "Kirulapone"), ("Kandy Innovation Centre Peradeniya", "Peradeniya")]:
        add("National Institute of Business Management", branch, town, ["nibm", f"nibm {town}"], NIBM, "statutory")

    # Existing workplace destinations remain available alongside education destinations.
    workplaces = [
        ("World Trade Center Colombo", "Colombo Fort", ["wtc", "world trade centre"]),
        ("Orion City IT Park", "Colombo", ["orion city"]), ("TRACE Expert City", "Maradana", ["trace city"]),
        ("Kandy City Centre", "Kandy", ["kcc"]), ("Galle City Centre", "Galle", ["galle office"]),
        ("Colombo South Teaching Hospital", "Dehiwala", ["kalubowila hospital", "csth"]),
        ("National Hospital of Sri Lanka", "Colombo", ["nhsl"]), ("Teaching Hospital Karapitiya", "Karapitiya", ["th karapitiya"]),
    ]
    for organization, town, aliases in workplaces:
        add(organization, None, town, aliases, "https://www.gov.lk/", "employment", "workplace")

    return sorted(rows, key=lambda row: (row["type"], row["organization"], row["branch"] or ""))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=PROJECT_ROOT / "datasets/catalog/sri_lanka_higher_education_destinations.json")
    args = parser.parse_args()
    rows = build()
    if len({row["name"] for row in rows}) != len(rows):
        raise ValueError("Catalog contains duplicate destination names")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "catalogVersion": "2026.08.25-v1",
        "retrievedOn": "2026-08-25",
        "scope": "UGC universities/campuses, public university centre networks, Ministry-approved non-state institutes, and official physical branches",
        "coordinatePolicy": "Reference coordinates are campus-local where curated and town-centre otherwise; verify road routes before deciding.",
        "destinations": rows,
    }
    args.output.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    organizations = {row["organization"] for row in rows if row["type"] == "campus"}
    print(json.dumps({"destinations": len(rows), "educationDestinations": sum(row["type"] == "campus" for row in rows), "organizations": len(organizations)}, indent=2))


if __name__ == "__main__":
    main()

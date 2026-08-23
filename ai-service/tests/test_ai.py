import importlib.util
import pathlib
import unittest

MODULE_PATH = pathlib.Path(__file__).parents[1] / "app.py"
SPEC = importlib.util.spec_from_file_location("smart_bodim_ai", MODULE_PATH)
ai = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ai)


class SearchTests(unittest.TestCase):
    def test_canonical_listing_contains_facilities(self):
        text = ai.canonical_listing({"title": "Room", "facilities": ["WiFi", "Parking"]})
        self.assertIn("WiFi", text)
        self.assertIn("Parking", text)

    def test_search_ranks_relevant_listing_first(self):
        listings = [
            {"id": 1, "title": "Quiet room", "description": "Near campus", "area": "Moratuwa", "city": "Colombo", "facilities": ["WiFi"]},
            {"id": 2, "title": "Large house", "description": "Beach road", "area": "Galle", "city": "Galle", "facilities": ["Parking"]},
        ]
        results = ai.cosine_rank("quiet room in Moratuwa with WiFi", listings)
        self.assertEqual(results[0]["id"], 1)

    def test_constraint_extraction(self):
        result = ai.extract_constraints("female room under Rs. 35,000 with wifi and kitchen")
        self.assertEqual(result["maxPrice"], 35000)
        self.assertEqual(result["genderRule"], "female_only")
        self.assertIn("WiFi", result["facilities"])


class ReviewTests(unittest.TestCase):
    def test_positive_review_analysis(self):
        result = ai.analyze_review("Very clean, quiet and safe with reliable wifi")
        self.assertEqual(result["label"], "positive")
        self.assertIn("cleanliness", result["aspects"])
        self.assertIn("WiFi", result["aspects"])

    def test_low_evidence_is_uncertain(self):
        self.assertEqual(ai.analyze_review("It is a room")["label"], "uncertain")

    def test_summary_requires_two_reviews(self):
        result = ai.summarize_reviews(["Clean room"])
        self.assertIn("Not enough", result["summary"])

    def test_summary_is_evidence_based(self):
        result = ai.summarize_reviews([
            "Very clean room and friendly owner",
            "Clean bathroom, safe area and owner responds quickly",
            "Quiet location but some traffic noise",
        ])
        self.assertEqual(result["sampleSize"], 3)
        self.assertIn("Based on 3 reviews", result["summary"])


if __name__ == "__main__":
    unittest.main()

"""Generate CC0 multilingual safety-language fixtures without claiming real events.

The records teach and test language understanding only. They are never imported
as live neighbourhood evidence and must not be used to claim real-world safety.
"""
from __future__ import annotations

import argparse
from pathlib import Path

from pipeline_common import write_jsonl


AREAS = [
    "Wattegedara, Maharagama", "Katubedda, Moratuwa", "Nugegoda", "Malabe",
    "Kandy", "Peradeniya", "Kelaniya", "Colombo 03", "Dehiwala", "Negombo",
    "Galle", "Matara", "Kurunegala", "Jaffna", "Anuradhapura", "Batticaloa",
    "Ratnapura", "Badulla", "Homagama", "Kaduwela", "Panadura", "Kalutara",
    "Sri Jayawardenepura Kotte", "Mount Lavinia",
]

SCENARIOS = {
    "en": {
        "lighting": ("The main road is well lit after sunset.", "The boarding lane is very dark and has no street lights."),
        "night_activity": ("There are people around and shops open late.", "The road is empty at night and feels deserted."),
        "transport": ("Buses run late and transport is available.", "There are no buses after 8 PM and transport stops early."),
        "isolation": ("There are houses nearby and neighbours around.", "The room is reached through an isolated road far from houses."),
        "harassment": ("I felt respected and experienced no harassment.", "I experienced unwanted attention and was followed near the lane."),
        "theft": ("My belongings were secure and there was no theft.", "A phone was stolen and neighbours warned about theft."),
        "road_safety": ("The area has a walkable pavement and a safe crossing.", "There is no pavement and speeding vehicles make crossing dangerous."),
        "flooding": ("The road stayed dry after rain and drainage was good.", "The road floods after rain because of poor drainage."),
        "emergency_access": ("A hospital and police station are nearby.", "The hospital is far and emergency access is difficult."),
    },
    "si": {
        "lighting": ("ප්‍රධාන පාරේ හොඳ ආලෝකය සහ වීදි ලාම්පු තියෙනවා.", "බෝඩිම් පාර අඳුරුයි සහ වීදි ලාම්පු නැහැ."),
        "night_activity": ("රෑටත් මිනිස්සු ඉන්නවා සහ කඩ විවෘතයි.", "රෑට පාර පාළුයි සහ රාත්‍රියේ නිහඬයි."),
        "transport": ("රෑටත් බස් තියෙනවා සහ ප්‍රවාහනය පහසුයි.", "රෑට බස් නැහැ සහ ප්‍රවාහනය අමාරුයි."),
        "isolation": ("අසල්වැසියන් ඉන්නවා සහ ගෙවල් ළඟයි.", "මේක පාළු පාරක් සහ ගෙවල් වලින් දුරයි."),
        "harassment": ("කිසිදු හිරිහැරයක් නැහැ සහ හැමෝම ගෞරවයෙන් හැසිරුණා.", "පාරේදී හිරිහැර කළා සහ කෙනෙක් පසුපස ආවා."),
        "theft": ("හොරකම් නැහැ සහ බඩු ආරක්ෂිතයි.", "මෙහි හොරකමක් සිදු වී බඩු සොරකම් කළා."),
        "road_safety": ("පදික මාර්ගය හොඳයි සහ පාර මාරුවීම පහසුයි.", "පදික මාර්ග නැහැ සහ වේගයෙන් වාහන යනවා."),
        "flooding": ("ගංවතුර නැහැ සහ ජලාපවහනය හොඳයි.", "වැස්සට පාර යට වෙනවා සහ ජලාපවහනය නරකයි."),
        "emergency_access": ("පොලිසිය ළඟයි සහ රෝහල ළඟයි.", "රෝහල දුරයි සහ හදිසි සේවා අමාරුයි."),
    },
    "ta": {
        "lighting": ("பிரதான சாலையில் நல்ல வெளிச்சம் மற்றும் தெரு விளக்கு உள்ளது.", "விடுதி செல்லும் வழி இருட்டு மற்றும் தெரு விளக்கு இல்லை."),
        "night_activity": ("இரவில் மக்கள் நடமாட்டம் உள்ளது மற்றும் கடைகள் திறந்திருக்கும்.", "இரவில் வெறிச்சோடி இந்த இடம் தனிமையாக உள்ளது."),
        "transport": ("இரவு பேருந்து உள்ளது மற்றும் போக்குவரத்து வசதி உள்ளது.", "இரவில் பேருந்து இல்லை மற்றும் போக்குவரத்து கிடைக்காது."),
        "isolation": ("அருகில் வீடுகள் மற்றும் அக்கம் பக்கத்தில் மக்கள் உள்ளனர்.", "இது தனிமையான சாலை மற்றும் ஒதுக்குப்புறமான வழி."),
        "harassment": ("தொந்தரவு இல்லை மற்றும் அனைவரும் மரியாதையாக நடந்தனர்.", "வழியில் தொந்தரவு செய்தார் மற்றும் ஒருவர் பின்தொடர்ந்தார்."),
        "theft": ("திருட்டு இல்லை மற்றும் பொருட்கள் பாதுகாப்பாக இருந்தன.", "இங்கு திருட்டு நடந்தது மற்றும் கைப்பேசி பறிப்பு பற்றி எச்சரித்தனர்."),
        "road_safety": ("நடைபாதை உள்ளது மற்றும் சாலை கடக்க வசதி உள்ளது.", "நடைபாதை இல்லை மற்றும் வாகனங்கள் வேகமாக செல்கின்றன."),
        "flooding": ("வெள்ளம் இல்லை மற்றும் வடிகால் நன்றாக உள்ளது.", "மழையில் சாலை வெள்ளம் மற்றும் நீர் தேங்கும்."),
        "emergency_access": ("காவல் நிலையம் அருகில் மற்றும் மருத்துவமனை அருகில் உள்ளது.", "மருத்துவமனை தூரம் மற்றும் அவசர உதவி இல்லை."),
    },
}

CONTEXTS = ["day visit", "evening visit", "weekday observation", "weekend observation"]


def generate() -> list[dict]:
    rows = []
    area_index = 0
    for language, scenarios in SCENARIOS.items():
        for aspect, (supportive, concern) in scenarios.items():
            for direction, text in (("supportive", supportive), ("concern", concern)):
                group_id = f"{language}-{aspect}-{direction}"
                for context_index, context in enumerate(CONTEXTS, 1):
                    area = AREAS[area_index % len(AREAS)]
                    area_index += 1
                    rows.append({
                        "id": f"safety-{language}-{aspect}-{direction}-{context_index:02d}",
                        "groupId": group_id,
                        "text": f"{text} [{context}; {area}]",
                        "label": "positive" if direction == "supportive" else "negative",
                        "safetyAspects": {aspect: direction},
                        "language": language,
                        "districtContext": area,
                        "timeContext": context,
                        "synthetic": True,
                        "verified": False,
                        "evidenceUse": "model-development-only",
                        "source": "BodimBuddy project-authored synthetic safety scenarios v1",
                        "license": "CC0-1.0",
                    })
    return rows


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=Path("../datasets/raw/sri_lanka_safety_reviews_v1.jsonl"))
    args = parser.parse_args()
    rows = generate()
    write_jsonl(args.output, rows)
    print({"output": str(args.output), "rows": len(rows), "synthetic": True, "liveEvidence": False})

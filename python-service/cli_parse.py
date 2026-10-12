import sys
import json
from app.parsers.schedule_parser import SchedulePdfParser
from app.resource_limits import MAX_PDF_BYTES

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Missing pdf file path"}))
        sys.exit(1)

    pdf_path = sys.argv[1]
    import_id = int(sys.argv[2]) if len(sys.argv) > 2 else 1

    try:
        with open(pdf_path, "rb") as f:
            pdf_bytes = f.read(MAX_PDF_BYTES + 1)

        parser = SchedulePdfParser()
        res = parser.parse(pdf_bytes, import_id)
        print(res.model_dump_json())
    except Exception as e:
        print(json.dumps({"error": str(e), "rows": []}))
        sys.exit(1)

from __future__ import annotations

import sys
from pathlib import Path

from docx import Document


def extract(in_path: Path) -> str:
    doc = Document(in_path)
    parts: list[str] = []
    for p in doc.paragraphs:
        text = (p.text or "").strip()
        if text:
            parts.append(text)
    return "\n".join(parts).strip() + "\n"


def main() -> None:
    if len(sys.argv) < 3:
        print("usage: extract_docx_text.py <input.docx> <output.txt>", file=sys.stderr)
        raise SystemExit(2)

    in_path = Path(sys.argv[1]).expanduser().resolve()
    out_path = Path(sys.argv[2]).expanduser().resolve()
    out_path.parent.mkdir(parents=True, exist_ok=True)

    out_path.write_text(extract(in_path), encoding="utf-8")
    print(str(out_path))


if __name__ == "__main__":
    main()


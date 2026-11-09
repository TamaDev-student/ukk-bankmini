from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
from xml.sax.saxutils import escape

OUTPUT = Path("tests/Fixtures/synthetic_students.xlsx")
OUTPUT.parent.mkdir(parents=True, exist_ok=True)

shared_strings = []
string_indexes = {}


def string_index(value):
    value = str(value)

    if value not in string_indexes:
        string_indexes[value] = len(shared_strings)
        shared_strings.append(value)

    return string_indexes[value]


def cell(reference, value):
    index = string_index(value)

    return (
        f'<c r="{reference}" t="s">'
        f'<v>{index}</v>'
        f'</c>'
    )


rows = []
row_number = 1


def add_row(cells):
    global row_number

    cell_xml = "".join(
        cell(f"{column}{row_number}", value)
        for column, value in cells
    )

    rows.append(
        f'<row r="{row_number}">{cell_xml}</row>'
    )

    row_number += 1


# Informasi tahun pelajaran.
add_row([("A", "TAHUN PELAJARAN 2026/2027")])

# -------------------------------------------------
# Blok 1: 35 siswa dari kelas yang belum dikonfigurasi
# -------------------------------------------------
add_row([("A", "KELAS: XII AK 4")])
add_row([
    ("A", "NO"),
    ("B", "NIS"),
    ("C", "NAMA SISWA"),
])

for i in range(1, 36):
    add_row([
        ("A", str(i)),
        ("B", f"TESTAK{i:04d}"),
        ("C", f"SISWA UJI AK 4 {i:04d}"),
    ])

# -------------------------------------------------
# Blok 2: 1.601 siswa dari kelas terkonfigurasi
# -------------------------------------------------
add_row([("A", "KELAS: XII TKJ 2")])
add_row([
    ("A", "NO"),
    ("B", "NIS"),
    ("C", "NAMA SISWA"),
])

for i in range(1, 1602):
    add_row([
        ("A", str(i)),
        ("B", f"TESTTKJ{i:04d}"),
        ("C", f"SISWA UJI TKJ 2 {i:04d}"),
    ])

sheet_xml = f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>
        {''.join(rows)}
    </sheetData>
</worksheet>
'''

shared_xml = f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
     count="{len(shared_strings)}"
     uniqueCount="{len(shared_strings)}">
    {''.join(f'<si><t>{escape(value)}</t></si>' for value in shared_strings)}
</sst>
'''

workbook_xml = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Data Siswa Uji" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>
'''

workbook_rels = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1"
                  Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"
                  Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2"
                  Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"
                  Target="sharedStrings.xml"/>
</Relationships>
'''

content_types = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="xml" ContentType="application/xml"/>
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Override PartName="/xl/workbook.xml"
              ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml"
              ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/sharedStrings.xml"
              ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>
'''

root_rels = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1"
                  Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
                  Target="xl/workbook.xml"/>
</Relationships>
'''

with ZipFile(OUTPUT, "w", ZIP_DEFLATED) as archive:
    archive.writestr("[Content_Types].xml", content_types)
    archive.writestr("_rels/.rels", root_rels)
    archive.writestr("xl/workbook.xml", workbook_xml)
    archive.writestr("xl/_rels/workbook.xml.rels", workbook_rels)
    archive.writestr("xl/worksheets/sheet1.xml", sheet_xml)
    archive.writestr("xl/sharedStrings.xml", shared_xml)

print(f"Fixture berhasil dibuat: {OUTPUT}")
print("Total siswa: 1.636")
print("XII AK 4: 35 siswa")
print("XII TKJ 2: 1.601 siswa")
print("Semua identitas dalam fixture ini adalah data sintetis.")
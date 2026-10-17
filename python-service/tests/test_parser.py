import fitz
import pytest
from app.parsers.schedule_parser import SchedulePdfParser


@pytest.mark.parametrize('label,expected_name,expected_nidn', [
    ('Turwan Aldi Putra, S.Kom. (Instruktur)', 'Turwan Aldi Putra, S.Kom. (Instruktur)', None),
    ('Turwan Aldi Putra, S.Kom. [0123456789]', 'Turwan Aldi Putra, S.Kom.', '0123456789'),
    ('Turwan Aldi Putra, S.Kom. (12345)', 'Turwan Aldi Putra, S.Kom. (12345)', None),
])
def test_only_ten_digit_nidn_is_extracted_from_lecturer_name(label, expected_name, expected_nidn):
    row = SchedulePdfParser()._extract_table_row(
        [label, 'Senin', '08:00-09:40', 'Pemrograman', 'IF 27 A', 'ONLINE'],
        {'lecturer': 0, 'day': 1, 'time': 2, 'course': 3, 'class': 4, 'room': 5},
        1, 1, 'GANJIL', '2027/2028'
    )
    assert row.normalized.lecturer_name == expected_name
    assert row.normalized.lecturer_external_code == expected_nidn
    assert SchedulePdfParser().version == '1.3.0'

def test_invalid_pdf_bytes():
    parser = SchedulePdfParser()
    res = parser.parse(b"not a valid pdf", import_id=1)
    assert len(res.issues) > 0
    assert res.issues[0].code == "PDF_INVALID"
    assert len(res.rows) == 0

def test_scanned_pdf_detects_ocr_required():
    # Create empty PDF with 1 page and no text
    doc = fitz.open()
    doc.new_page()
    pdf_bytes = doc.tobytes()
    doc.close()

    parser = SchedulePdfParser()
    res = parser.parse(pdf_bytes, import_id=2)
    assert len(res.issues) > 0
    assert any(iss.code == "OCR_REQUIRED" for iss in res.issues)

def test_text_pdf_parsing():
    # Create PDF with a text line that contains time and course
    doc = fitz.open()
    page = doc.new_page()
    page.insert_text((50, 100), "Dosen Pengajar Contoh Senin 09.00-10.40 Algoritma Pemrograman IF23A R. 301")
    pdf_bytes = doc.tobytes()
    doc.close()

    parser = SchedulePdfParser()
    res = parser.parse(pdf_bytes, import_id=3)
    assert res.page_count == 1
    # Check if a row was parsed
    assert len(res.rows) >= 1
    row = res.rows[0]
    assert row.normalized.start_time == "09:00"
    assert row.normalized.end_time == "10:40"
    assert row.normalized.day_of_week == 1
    assert row.normalized.room_name is None
    assert any(issue.severity == 'ERROR' for issue in row.issues)


@pytest.mark.parametrize('label', ['ONLINE 2', 'ONLINE\n2', 'Online 4', 'online', 'SPADA', 'ZOOM', 'DARING'])
def test_online_labels_keep_their_mode_and_never_invent_a_physical_room(label):
    cell = SchedulePdfParser()._parse_matrix_cell(
        f'TS 26 A, TS 26 B,\nTI 26 A\nAplikasi Komputer\n(Teori)\n{label}'
    )
    assert cell['delivery_mode'] == 'ONLINE'
    assert cell['room_name'] == label.replace('\n', ' ')
    assert cell['class_name'] == 'TS 26 A, TS 26 B, TI 26 A'
    assert cell['course_name'] == 'Aplikasi Komputer (Teori)'
    assert not cell['issues']


@pytest.mark.parametrize('room', ['LAB 1 GSG (50)', 'LAB\nGAMBAR\n(50)', '301 B (50)', 'R. Gym', 'R. WD I FSIP', 'MASJID'])
def test_physical_rooms_are_read_case_insensitively_without_a_fallback(room):
    cell = SchedulePdfParser()._parse_matrix_cell(f'IF 26 A\nPemrograman\n{room}')
    assert cell['delivery_mode'] == 'OFFLINE'
    assert cell['room_name'] == room.replace('\n', ' ')
    assert cell['course_name'] == 'Pemrograman'
    assert not cell['issues']


def test_inline_modes_and_wrapped_class_room_names_are_separate_from_the_course():
    cell = SchedulePdfParser()._parse_matrix_cell(
        'Dr. Dosen Contoh, M.Pd.\nPO RPL A, PO RPL\nB\nManajemen\nOlahraga ONLINE, SPADA\nOnline 2'
    )
    assert cell['class_name'] == 'PO RPL A, PO RPL B'
    assert cell['course_name'] == 'Manajemen Olahraga'
    assert cell['room_name'] == 'SPADA / Online 2'
    assert cell['delivery_mode'] == 'ONLINE'

    offline = SchedulePdfParser()._parse_matrix_cell('IF 26 A\nPemrograman\nLAB 1 A\n(50)')
    assert offline['room_name'] == 'LAB 1 A (50)'
    assert offline['course_name'] == 'Pemrograman'


def test_missing_or_conflicting_delivery_evidence_requires_review():
    parser = SchedulePdfParser()
    missing = parser._parse_matrix_cell('IF 26 A\nPemrograman')
    assert missing['delivery_mode'] is None
    assert missing['room_name'] is None
    assert any(issue.field == 'delivery_mode' and issue.severity == 'ERROR' for issue in missing['issues'])

    conflict = parser._parse_matrix_cell('PO 24 A\nKesehatan Olahraga OFFLINE\nOnline 1')
    assert any(issue.code == 'CONFLICTING_MODE' for issue in conflict['issues'])

    hybrid = parser._parse_matrix_cell('PM 25 A\nMatematika HYBRID\nR. 203 B')
    assert hybrid['delivery_mode'] == 'HYBRID'
    assert hybrid['course_name'] == 'Matematika'


def test_empty_timetable_placeholders_are_not_courses():
    for placeholder in ['---', '-x-', '-X-', 'X', 'x']:
        assert SchedulePdfParser()._parse_matrix_cell(placeholder) is None


def test_unrecognized_nonempty_cells_are_kept_for_review_instead_of_silently_dropped():
    cell = SchedulePdfParser()._parse_matrix_cell('Pemrograman\nONLINE 2')
    assert cell['class_name'] == ''
    assert cell['delivery_mode'] == 'ONLINE'
    assert any(issue.field == 'class_name' and issue.severity == 'ERROR' for issue in cell['issues'])


def test_flat_table_online_mode_is_preserved_and_conflicting_physical_room_requires_review():
    parser = SchedulePdfParser()
    columns = {'lecturer': 0, 'course': 1, 'class': 2, 'day': 3, 'time': 4, 'room': 5, 'mode': 6}
    values = ['Dosen Contoh', 'Aplikasi Komputer', 'TS 26 A', 'Sabtu', '13:00-14:40', 'ONLINE 2', 'ONLINE']
    row = parser._extract_table_row(values, columns, 1, 1, 'GANJIL', '2026/2027')
    assert row.normalized.delivery_mode == 'ONLINE'
    assert row.normalized.room_name == 'ONLINE 2'
    assert not row.issues
    values[5] = 'R. 201'
    row = parser._extract_table_row(values, columns, 1, 1, 'GANJIL', '2026/2027')
    assert any(issue.code == 'CONFLICTING_MODE' for issue in row.issues)


def test_flat_table_missing_fields_are_not_filled_with_invented_class_day_or_time():
    columns = {'lecturer': 0, 'course': 1, 'class': 2, 'day': 3, 'time': 4, 'room': 5}
    row = SchedulePdfParser()._extract_table_row(['Dosen Contoh', 'Aplikasi Komputer', '', '', '', 'ONLINE 2'], columns, 1, 1, None, None)
    assert row.normalized.day_of_week is None
    assert row.normalized.class_name == ''
    assert row.normalized.start_time == ''
    assert row.normalized.end_time == ''
    assert {issue.field for issue in row.issues if issue.severity == 'ERROR'} >= {'class_name', 'day_of_week', 'time'}


def test_pdf_background_rectangles_do_not_split_a_merged_schedule_cell():
    doc = fitz.open()
    page = doc.new_page(width=800, height=600)
    page.insert_text((35, 55), 'Nama Dosen : Dr. Dosen Contoh, M.Kom.')
    x_positions = [30, 90, 200, 310, 420, 530, 640, 750]
    y_positions = [80, 110, 135, 200]
    for top, bottom in zip(y_positions[1:], y_positions[2:]):
        page.draw_rect(fitz.Rect(640, top, 750, bottom), color=None, fill=(1, 1, 1))
    for x in x_positions:
        page.draw_rect(fitz.Rect(x, 80, x + 0.5, 200), color=None, fill=(0, 0, 0))
    for y in y_positions:
        right = 640 if y == 135 else 750
        page.draw_rect(fitz.Rect(30, y, right, y + 0.5), color=None, fill=(0, 0, 0))
    for index, label in enumerate(['Waktu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu']):
        page.insert_text((x_positions[index] + 5, 100), label, fontsize=9)
    page.insert_text((35, 127), '08.00', fontsize=9)
    page.insert_text((35, 155), '09.00', fontsize=9)
    page.insert_text((648, 127), 'MBI 25 B', fontsize=9)
    page.insert_text((648, 152), 'Metodologi Penelitian', fontsize=9)
    page.insert_text((648, 172), 'ONLINE 2', fontsize=9)
    result = SchedulePdfParser().parse(doc.tobytes(), import_id=4)
    doc.close()

    assert len(result.rows) == 1
    row = result.rows[0]
    assert row.normalized.lecturer_name == 'Dr. Dosen Contoh, M.Kom.'
    assert row.normalized.start_time == '08:00'
    assert row.normalized.day_of_week == 6
    assert row.normalized.course_name == 'Metodologi Penelitian', row.raw
    assert row.normalized.delivery_mode == 'ONLINE'
    assert row.normalized.room_name == 'ONLINE 2'
    assert row.source.bbox is not None

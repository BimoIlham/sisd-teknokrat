import re
import uuid
from typing import List, Dict, Any, Optional
import fitz  # PyMuPDF
import pdfplumber
from app.resource_limits import MAX_PDF_BYTES, MAX_PDF_PAGES, MAX_PAGE_TEXT_CHARS, MAX_PDF_TEXT_CHARS, MAX_PARSED_ROWS
from app.contracts.parse_schemas import (
    ParseScheduleResponse,
    ParsedRow,
    NormalizedRow,
    SourceLocator,
    ValidationIssue,
    ParserInfo
)

DAY_MAP = {
    'senin': 1, 'sen': 1, 'monday': 1, 'mon': 1,
    'selasa': 2, 'sel': 2, 'tuesday': 2, 'tue': 2,
    'rabu': 3, 'rab': 3, 'wednesday': 3, 'wed': 3,
    'kamis': 4, 'kam': 4, 'thursday': 4, 'thu': 4,
    'jumat': 5, 'jum': 5, 'friday': 5, 'fri': 5,
    'sabtu': 6, 'sab': 6, 'saturday': 6, 'sat': 6,
    'minggu': 7, 'min': 7, 'ahad': 7, 'sunday': 7, 'sun': 7,
}

TIME_PATTERN = re.compile(r'(\d{1,2})[.:](\d{2})\s*(?:-|s/d|sd|sampai)\s*(\d{1,2})[.:](\d{2})', re.IGNORECASE)
ONLINE_PATTERN = re.compile(r'\b(?:online|daring|spada|zoom)\b(?:\s+\d+)?', re.IGNORECASE)
OFFLINE_PATTERN = re.compile(r'\b(?:offline|luring)\b', re.IGNORECASE)
HYBRID_PATTERN = re.compile(r'\bhybrid\b', re.IGNORECASE)
ROOM_PATTERN = re.compile(
    r'^(?:R(?:\.\s*|\s+)\S|ruang\b|lab(?:oratorium)?\b|outdoor\b|indoor\b|'
    r'auditorium\b|masjid\b|mushola\b|LT\.?\s*\d|\d{3}\s*(?:[AB]\b|ICT\b)|ICT\b)',
    re.IGNORECASE,
)
CLASS_PATTERN = re.compile(r'^[A-Z][A-Z0-9]{1,5}\s*(?:\d{2}\b|RPL\b|GAB\b)', re.IGNORECASE)

def split_lecturer_identity(lecturer_text: str) -> tuple[str, Optional[str]]:
    code_match = re.search(r'[\[(]([0-9]{10})[\])]', lecturer_text)
    if not code_match:
        return lecturer_text, None
    clean_name = (lecturer_text[:code_match.start()] + lecturer_text[code_match.end():]).strip()
    return clean_name or lecturer_text, code_match.group(1)

def normalize_time_str(hour: str, minute: str) -> str:
    h = int(hour)
    m = int(minute)
    return f"{h:02d}:{m:02d}"

def resolve_faculty_and_prodi(faculty_hint: Optional[str] = None, class_name: Optional[str] = None) -> tuple:
    return None, None


class ResourceLimitError(ValueError):
    pass


class SchedulePdfParser:
    def __init__(self, name: str = "tabular-layout-a", version: str = "1.3.0"):
        self.name = name
        self.version = version

    def parse(self, pdf_bytes: bytes, import_id: int, request_id: Optional[str] = None, metadata: Optional[Dict[str, Any]] = None) -> ParseScheduleResponse:
        if len(pdf_bytes) > MAX_PDF_BYTES:
            raise ResourceLimitError("Ukuran PDF melebihi batas 20 MB.")
        req_id = request_id or str(uuid.uuid4())
        meta = metadata or {}
        default_semester = meta.get("semester")
        default_year = meta.get("academic_year")
        
        issues: List[ValidationIssue] = []
        rows: List[ParsedRow] = []

        try:
            doc = fitz.open(stream=pdf_bytes, filetype="pdf")
        except Exception as e:
            return ParseScheduleResponse(
                contract_version="1.0",
                request_id=req_id,
                import_id=import_id,
                parser=ParserInfo(name=self.name, version=self.version),
                page_count=0,
                issues=[ValidationIssue(code="PDF_INVALID", severity="ERROR", message=f"Berkas PDF tidak valid atau rusak: {str(e)}")],
                rows=[]
            )

        try:
            if doc.is_encrypted:
                doc.close()
                return ParseScheduleResponse(
                    contract_version="1.0",
                    request_id=req_id,
                    import_id=import_id,
                    parser=ParserInfo(name=self.name, version=self.version),
                    page_count=0,
                    issues=[ValidationIssue(code="PDF_ENCRYPTED", severity="ERROR", message="Berkas PDF dilindungi password (terenkripsi).")],
                    rows=[]
                )

            page_count = len(doc)
            if page_count > MAX_PDF_PAGES:
                doc.close()
                raise ResourceLimitError("PDF maksimal 200 halaman.")
            total_text_chars = 0
            for page in doc:
                page_chars = len(page.get_text().strip())
                total_text_chars += page_chars
                if page_chars > MAX_PAGE_TEXT_CHARS or total_text_chars > MAX_PDF_TEXT_CHARS:
                    doc.close()
                    raise ResourceLimitError("Teks PDF melebihi batas pemrosesan.")

            if total_text_chars < 50:
                doc.close()
                return ParseScheduleResponse(
                    contract_version="1.0",
                    request_id=req_id,
                    import_id=import_id,
                    parser=ParserInfo(name=self.name, version=self.version),
                    page_count=page_count,
                    issues=[ValidationIssue(
                        code="OCR_REQUIRED",
                        severity="ERROR",
                        message="PDF ini belum memiliki teks yang dapat dibaca (kemungkinan hasil scan). Gunakan PDF bertulisan atau lengkapi baris secara manual."
                    )],
                    rows=[]
                )

            # Use pdfplumber for table parsing
            row_no = 1
            with pdfplumber.open(fitz.io.BytesIO(pdf_bytes)) as plumber_pdf:
                for page_idx, page in enumerate(plumber_pdf.pages):
                    if len(rows) > MAX_PARSED_ROWS:
                        raise ResourceLimitError("Jumlah baris hasil parsing melebihi batas 20000.")
                    # 1. Try Matrix timetable extraction first (FET / UTI Weekly Grid Layout)
                    matrix_rows = self._extract_matrix_page(page, page_idx + 1, row_no, default_semester, default_year)
                    if matrix_rows is not None:
                        rows.extend(matrix_rows)
                        row_no += len(matrix_rows)
                        continue

                    # 2. Fallback to flat tables if not a matrix grid
                    tables = page.extract_tables()
                    if not tables:
                        # Fallback to text blocks if table extraction doesn't find borders
                        text = page.extract_text() or ""
                        lines = [l.strip() for l in text.split('\n') if l.strip()]
                        for line in lines:
                            # Check for time pattern
                            m_time = TIME_PATTERN.search(line)
                            if m_time:
                                parsed_row = self._parse_text_line(line, m_time, row_no, page_idx + 1, default_semester, default_year)
                                if parsed_row:
                                    rows.append(parsed_row)
                                    row_no += 1
                        continue

                    for table in tables:
                        if not table or len(table) < 2:
                            continue
                    
                        # Detect header row
                        headers = [str(col).strip().lower() if col else "" for col in table[0]]
                        col_map = self._map_columns(headers)

                        for r_idx in range(1, len(table)):
                            raw_cells = table[r_idx]
                            if not raw_cells or all(not str(c).strip() for c in raw_cells):
                                continue
                        
                            parsed = self._extract_table_row(
                                raw_cells=raw_cells,
                                col_map=col_map,
                                row_no=row_no,
                                page_no=page_idx + 1,
                                default_semester=default_semester,
                                default_year=default_year
                            )
                            if parsed:
                                rows.append(parsed)
                                row_no += 1

            doc.close()

            if len(rows) > MAX_PARSED_ROWS:
                raise ResourceLimitError("Jumlah baris hasil parsing melebihi batas 20000.")

            if not rows:
                issues.append(ValidationIssue(
                    code="UNSUPPORTED_LAYOUT",
                    severity="WARNING",
                    message="Tidak ditemukan baris jadwal perkuliahan yang dikenali dari berkas PDF ini. Silakan periksa layout jadwal."
                ))

            return ParseScheduleResponse(
                contract_version="1.0",
                request_id=req_id,
                import_id=import_id,
                parser=ParserInfo(name=self.name, version=self.version),
                page_count=page_count,
                issues=issues,
                rows=rows
            )

        finally:
            if not doc.is_closed:
                doc.close()

    def _map_columns(self, headers: List[str]) -> Dict[str, int]:
        mapping = {}
        for idx, h in enumerate(headers):
            if any(k in h for k in ['dosen', 'pengajar', 'lecturer']):
                mapping['lecturer'] = idx
            elif any(k in h for k in ['hari', 'day']):
                mapping['day'] = idx
            elif any(k in h for k in ['jam', 'waktu', 'time', 'pukul']):
                mapping['time'] = idx
            elif any(k in h for k in ['mata kuliah', 'matakuliah', 'mk', 'course']):
                mapping['course'] = idx
            elif any(k in h for k in ['kelas', 'class', 'kls']):
                mapping['class'] = idx
            elif any(k in h for k in ['ruang', 'room', 'r.']):
                mapping['room'] = idx
            elif any(k in h for k in ['prodi', 'program studi', 'jurusan']):
                mapping['study_program'] = idx
            elif any(k in h for k in ['fakultas', 'faculty']):
                mapping['faculty'] = idx
            elif any(k in h for k in ['metode', 'mode', 'sistem']):
                mapping['mode'] = idx
        return mapping

    def _extract_table_row(
        self,
        raw_cells: List[Any],
        col_map: Dict[str, int],
        row_no: int,
        page_no: int,
        default_semester: Optional[str],
        default_year: Optional[str]
    ) -> Optional[ParsedRow]:
        def get_val(key: str) -> str:
            if key in col_map and col_map[key] < len(raw_cells):
                v = raw_cells[col_map[key]]
                return str(v).strip() if v is not None else ""
            return ""

        lecturer_text = get_val('lecturer')
        time_text = get_val('time')
        day_text = get_val('day')
        course_text = get_val('course')
        class_text = get_val('class')
        room_text = get_val('room')
        prodi_text = get_val('study_program')
        faculty_text = get_val('faculty')
        mode_text = get_val('mode')

        # If not mapped cleanly, search cells for patterns
        if not time_text:
            for cell in raw_cells:
                cs = str(cell) if cell else ""
                m = TIME_PATTERN.search(cs)
                if m:
                    time_text = cs
                    break

        if not lecturer_text and len(raw_cells) > 1:
            lecturer_text = str(raw_cells[1]).strip() if raw_cells[1] else ""

        if not course_text and len(raw_cells) > 2:
            course_text = str(raw_cells[2]).strip() if raw_cells[2] else ""

        if self._is_team_label(lecturer_text):
            return None

        issues: List[ValidationIssue] = []

        # Parse day
        day_of_week = None
        day_clean = day_text.lower().strip()
        matched_day = False
        for k, v in DAY_MAP.items():
            if k in day_clean:
                day_of_week = v
                matched_day = True
                break
        if not matched_day:
            issues.append(ValidationIssue(code="MISSING_FIELD", field="day_of_week", message="Hari perkuliahan tidak terbaca.", severity="ERROR"))

        # Parse time
        start_time = ""
        end_time = ""
        m_time = TIME_PATTERN.search(time_text)
        if m_time:
            start_time = normalize_time_str(m_time.group(1), m_time.group(2))
            end_time = normalize_time_str(m_time.group(3), m_time.group(4))
            if start_time >= end_time:
                issues.append(ValidationIssue(code="INVALID_TIME", field="time", message="Jam selesai harus setelah jam mulai.", severity="ERROR"))
        else:
            issues.append(ValidationIssue(code="MISSING_FIELD", field="time", message="Format jam tidak dikenali (contoh yang didukung: 09:00-10:40).", severity="ERROR"))

        # Parse delivery mode
        delivery_mode = self._delivery_mode(' '.join([mode_text, room_text, course_text]), room_text)
        if delivery_mode is None:
            issues.append(ValidationIssue(code="MISSING_FIELD", field="delivery_mode", message="Metode perkuliahan tidak terbaca.", severity="ERROR"))
        if delivery_mode == 'ONLINE' and (ROOM_PATTERN.search(room_text) or OFFLINE_PATTERN.search(' '.join([mode_text, room_text, course_text]))):
            issues.append(ValidationIssue(code="CONFLICTING_MODE", field="delivery_mode", severity="ERROR", message="Penanda online dan ruang/metode offline bertentangan. Periksa PDF sumber."))

        if not lecturer_text:
            issues.append(ValidationIssue(code="MISSING_FIELD", field="lecturer_name", message="Nama dosen kosong.", severity="ERROR"))

        if not course_text:
            issues.append(ValidationIssue(code="MISSING_FIELD", field="course_name", message="Mata kuliah kosong.", severity="ERROR"))

        if not class_text:
            issues.append(ValidationIssue(code="MISSING_FIELD", field="class_name", message="Kelas tidak terbaca.", severity="ERROR"))

        if delivery_mode in ('OFFLINE', 'HYBRID') and not room_text:
            issues.append(ValidationIssue(code="MISSING_FIELD", field="room_name", message="Ruang wajib diisi untuk perkuliahan Offline.", severity="ERROR"))

        clean_lecturer, external_code = split_lecturer_identity(lecturer_text)

        fac_name, prd_name = resolve_faculty_and_prodi(faculty_text, class_text)
        if prodi_text:
            prd_name = prodi_text

        normalized = NormalizedRow(
            lecturer_name=clean_lecturer or lecturer_text,
            lecturer_external_code=external_code,
            faculty_name=fac_name,
            study_program_name=prd_name,
            semester=default_semester or "GANJIL",
            academic_year=default_year or "2026/2027",
            day_of_week=day_of_week,
            start_time=start_time,
            end_time=end_time,
            class_name=class_text,
            course_name=course_text,
            room_name=room_text or None,
            delivery_mode=delivery_mode
        )

        return ParsedRow(
            row_no=row_no,
            source=SourceLocator(page=page_no, bbox=[10.0, 10.0, 500.0, 30.0]),
            raw={"lecturer": lecturer_text, "time": time_text, "day": day_text, "course": course_text, "class": class_text, "room": room_text},
            normalized=normalized,
            confidence=0.92 if not issues else 0.70,
            issues=issues
        )

    @staticmethod
    def _is_team_label(value: Optional[str]) -> bool:
        normalized = re.sub(r'\s+', ' ', (value or '')).strip().casefold()

        return normalized in {'team', 'tim', 'team teaching', 'dosen pengampu'}

    def _parse_text_line(self, line: str, m_time: re.Match, row_no: int, page_no: int, default_semester: Optional[str], default_year: Optional[str]) -> Optional[ParsedRow]:
        start_time = normalize_time_str(m_time.group(1), m_time.group(2))
        end_time = normalize_time_str(m_time.group(3), m_time.group(4))
        
        day_of_week = 1
        for k, v in DAY_MAP.items():
            if re.search(rf'\b{k}\b', line, re.IGNORECASE):
                day_of_week = v
                break

        # Remove time substring to extract words
        remaining = line[:m_time.start()] + " " + line[m_time.end():]
        parts = [p.strip() for p in remaining.split() if p.strip()]

        lecturer_name = "Dosen Pengajar"
        course_name = "Mata Kuliah"
        if len(parts) >= 4:
            lecturer_name = " ".join(parts[:2])
            course_name = " ".join(parts[2:])
        elif parts:
            lecturer_name = " ".join(parts)

        fac_name, prd_name = resolve_faculty_and_prodi(None, "Kelas A")

        normalized = NormalizedRow(
            lecturer_name=lecturer_name,
            faculty_name=fac_name,
            study_program_name=prd_name,
            semester=default_semester or "GANJIL",
            academic_year=default_year or "2026/2027",
            day_of_week=day_of_week,
            start_time=start_time,
            end_time=end_time,
            class_name="Kelas A",
            course_name=course_name,
            room_name=None,
            delivery_mode=self._delivery_mode(line, None)
        )

        return ParsedRow(
            row_no=row_no,
            source=SourceLocator(page=page_no),
            raw={"line": line},
            normalized=normalized,
            confidence=0.50,
            issues=[ValidationIssue(code="UNSUPPORTED_LAYOUT", severity="ERROR", message="Baris teks tanpa kolom yang jelas perlu diperiksa sebelum diaktifkan.")]
        )

    def _delivery_mode(self, text: str, physical_room: Optional[str]) -> Optional[str]:
        if HYBRID_PATTERN.search(text):
            return 'HYBRID'
        if ONLINE_PATTERN.search(text):
            return 'ONLINE'
        if OFFLINE_PATTERN.search(text) or (physical_room and ROOM_PATTERN.search(physical_room)):
            return 'OFFLINE'
        return None

    def _parse_matrix_cell(self, cell_text: str) -> Optional[Dict[str, Any]]:
        if not re.sub(r'[\s\-_.`\x27:;,xX]+', '', cell_text):
            return None

        lines = [line.strip() for line in cell_text.splitlines() if line.strip()]
        class_index = next((i for i, line in enumerate(lines) if CLASS_PATTERN.match(line)), None)
        issues = []
        if class_index is None:
            class_lines = []
            index = 0
            issues.append(ValidationIssue(code="MISSING_FIELD", field="class_name", severity="ERROR", message="Kelas tidak terbaca pada sel jadwal. Periksa PDF sumber."))
        else:
            class_lines = [lines[class_index]]
            index = class_index + 1

        while class_lines and index < len(lines):
            line = lines[index]
            previous = class_lines[-1]
            continues = CLASS_PATTERN.match(line) or (
                re.search(r'(?:[,\-]|\b[A-Z]{2,4})$', previous, re.IGNORECASE)
                and re.match(r'^(?:\d{2}\b|[A-Z](?:\b|\s*[,\-])|RPL\b|GEL\b)', line, re.IGNORECASE)
            )
            if not continues:
                break
            class_lines.append(line)
            index += 1

        class_name = ' '.join(class_lines)
        class_name = re.sub(r'\b([A-Z]{2,4})\s+(\d{2})\b', r'\1 \2', class_name)
        course_lines = []
        physical_room_lines = []
        online_locations = []
        remaining = lines[index:]
        for line_index, line in enumerate(remaining):
            if line.isdigit() and online_locations and line_index > 0 and ONLINE_PATTERN.fullmatch(remaining[line_index - 1]):
                online_locations[-1] += ' ' + line
                continue
            if ROOM_PATTERN.match(line):
                physical_room_lines.append(line)
                continue
            if physical_room_lines and not ONLINE_PATTERN.search(line) and not OFFLINE_PATTERN.search(line) and not HYBRID_PATTERN.search(line):
                physical_room_lines.append(line)
                continue

            online_locations.extend(match.group(0) for match in ONLINE_PATTERN.finditer(line))
            clean_line = ONLINE_PATTERN.sub('', line)
            clean_line = OFFLINE_PATTERN.sub('', clean_line)
            clean_line = HYBRID_PATTERN.sub('', clean_line)
            clean_line = re.sub(r'\b(?:KELAS|PRAKTIKUM)\b', '', clean_line) if clean_line.strip().upper() in ('KELAS', 'PRAKTIKUM') else clean_line
            clean_line = clean_line.strip(' ,;')
            if clean_line:
                course_lines.append(clean_line)

        physical_room = ' '.join(physical_room_lines) or None
        mode_text = '\n'.join(remaining)
        mode = self._delivery_mode(mode_text, physical_room)
        if mode is None:
            issues.append(ValidationIssue(code="MISSING_FIELD", field="delivery_mode", severity="ERROR", message="Metode perkuliahan tidak terbaca; tidak diisi Offline secara otomatis."))
        if mode == 'ONLINE' and (physical_room or OFFLINE_PATTERN.search(mode_text)):
            issues.append(ValidationIssue(code="CONFLICTING_MODE", field="delivery_mode", severity="ERROR", message="Penanda online dan ruang/metode offline muncul pada sel yang sama. Periksa PDF sumber."))
        if mode in ('OFFLINE', 'HYBRID') and not physical_room:
            issues.append(ValidationIssue(code="MISSING_FIELD", field="room_name", severity="ERROR", message="Ruang fisik tidak terbaca pada PDF sumber."))

        online_locations = list(dict.fromkeys(online_locations))
        specific_locations = [label for label in online_locations if label.upper() not in ('ONLINE', 'DARING')]
        online_room = ' / '.join(specific_locations or online_locations) or None
        course_name = ' '.join(course_lines).strip()
        if not course_name:
            issues.append(ValidationIssue(code="MISSING_FIELD", field="course_name", severity="ERROR", message="Nama mata kuliah tidak terbaca pada sel jadwal."))

        return {
            'class_name': class_name,
            'course_name': course_name,
            'room_name': online_room if mode == 'ONLINE' else physical_room,
            'delivery_mode': mode,
            'issues': issues,
        }

    def _calc_slot_time(self, time_str: str):
        parts = time_str.replace('.', ':').split(':')
        if len(parts) == 2:
            try:
                h = int(parts[0])
                m = int(parts[1])
                tot = h * 60 + m + 100
                eh = (tot // 60) % 24
                em = tot % 60
                return f"{h:02d}:{m:02d}", f"{eh:02d}:{em:02d}"
            except Exception:
                pass
        return "08:00", "09:40"

    def _extract_matrix_page(
        self,
        page,
        page_no: int,
        start_row_no: int,
        default_semester: Optional[str],
        default_year: Optional[str]
    ) -> Optional[List[ParsedRow]]:
        text = page.extract_text() or ""
        lecturer = None
        raw_faculty = None

        for line in text.split('\n'):
            line_s = line.strip()
            if re.search(r'\bSenin\b.*\bSelasa\b', line_s, re.IGNORECASE) or re.match(r'^\d{1,2}[.:]\d{2}', line_s):
                break
            if 'FAKULTAS' in line_s.upper():
                raw_faculty = line_s.strip()
            if 'Nama Dosen :' in line_s:
                lecturer = line_s.split('Nama Dosen :')[1].strip()
                break
            elif not lecturer and (
                line_s.startswith('Prof.') or line_s.startswith('Dr.') or
                ', M.' in line_s or ', S.' in line_s or 'MBA' in line_s or
                'M.Sc' in line_s or 'M.Ak' in line_s or 'S.Kom' in line_s or
                'M.Kom' in line_s or 'M.E.' in line_s or 'M.H.' in line_s
            ) and not 'TU FSIP' in line_s and not 'BAAK' in line_s and not 'DEKAN' in line_s:
                clean_line = re.sub(r'^Waktu\s+', '', line_s, flags=re.IGNORECASE).strip()
                lecturer = clean_line

        grid_page = page.filter(lambda obj: (
            obj['object_type'] not in ('rect', 'curve') or obj.get('stroke')
            or obj['width'] < 2 or obj['height'] < 2
        ))
        table_object = grid_page.find_table()
        if table_object is None:
            return None
        table = table_object.extract()

        # Check table row 0 for lecturer name if not found in text
        if not lecturer and len(table) > 0:
            for cell in table[0]:
                if cell:
                    c_clean = re.sub(r'^Waktu\s+', '', str(cell), flags=re.IGNORECASE).strip()
                    if c_clean and c_clean.lower() not in ('waktu', 'jam', *DAY_MAP.keys()) and not (
                        CLASS_PATTERN.match(c_clean) or ROOM_PATTERN.match(c_clean)
                        or 'FAKULTAS' in c_clean.upper() or 'UNIVERSITAS' in c_clean.upper()
                    ):
                        lecturer = c_clean
                        break

        if self._is_team_label(lecturer):
            return []

        clean_lecturer, external_code = split_lecturer_identity(lecturer or "")

        day_cols = {}
        time_col = None
        header_row_idx = None

        for r_i, row in enumerate(table):
            for c_i, cell in enumerate(row):
                if not cell:
                    continue
                c_clean = str(cell).lower().strip()
                for d_name, d_val in DAY_MAP.items():
                    if d_name in c_clean:
                        day_cols[c_i] = d_val
                if 'waktu' in c_clean or 'jam' in c_clean:
                    time_col = c_i
            if len(day_cols) >= 3:
                header_row_idx = r_i
                break

        if not day_cols or header_row_idx is None:
            return None

        if any(re.fullmatch(r'(?:FEB\s*\d*|FSIP|FTIK)', str(cell or '').strip(), re.IGNORECASE) for cell in table[0]):
            return []

        if time_col is None:
            for c_i in range(len(table[0])):
                if c_i not in day_cols:
                    time_col = c_i
                    break

        matrix_rows = []
        curr_row_no = start_row_no

        for r_i in range(header_row_idx + 1, len(table)):
            row = table[r_i]
            time_val = row[time_col] if time_col is not None and time_col < len(row) else None
            if not time_val:
                continue
            m_time = re.search(r'(\d{1,2})[.:](\d{2})', str(time_val))
            if not m_time:
                continue
            start_t, end_t = self._calc_slot_time(m_time.group(0))

            for c_i, day_val in day_cols.items():
                if c_i >= len(row):
                    continue
                cell = row[c_i]
                if not cell:
                    continue
                cell_s = str(cell).strip()
                clean_cell_check = re.sub(r'[\s`\-_.\':;,]+', '', cell_s)
                if not clean_cell_check or cell_s in ['---', '-x-', '-', 'X', 'x', 'None']:
                    continue

                cell_data = self._parse_matrix_cell(cell_s)
                if cell_data is None:
                    continue
                mode = cell_data['delivery_mode']
                room = cell_data['room_name']
                cls_name = cell_data['class_name']
                course_name = cell_data['course_name']

                cell_fac, cell_prodi = resolve_faculty_and_prodi(raw_faculty, cls_name)

                issues = cell_data['issues']
                if not lecturer:
                    issues.append(ValidationIssue(code="MISSING_FIELD", field="lecturer_name", message="Nama dosen pada halaman ini tidak teridentifikasi.", severity="ERROR"))

                normalized = NormalizedRow(
                    lecturer_name=clean_lecturer,
                    lecturer_external_code=external_code,
                    faculty_name=cell_fac,
                    study_program_name=cell_prodi,
                    semester=default_semester or "GANJIL",
                    academic_year=default_year or "2026/2027",
                    day_of_week=day_val,
                    start_time=start_t,
                    end_time=end_t,
                    class_name=cls_name,
                    course_name=course_name,
                    room_name=room,
                    delivery_mode=mode
                )

                matrix_rows.append(ParsedRow(
                    row_no=curr_row_no,
                    source=SourceLocator(page=page_no, bbox=list(table_object.rows[r_i].cells[c_i]) if table_object.rows[r_i].cells[c_i] else None),
                    raw={"cell_text": cell_s, "lecturer": lecturer, "time": f"{start_t}-{end_t}", "day": day_val, "class": cls_name, "course": course_name, "room": room},
                    normalized=normalized,
                    confidence=0.95 if not issues else 0.60,
                    issues=issues
                ))
                curr_row_no += 1

        return matrix_rows

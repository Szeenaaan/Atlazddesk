<?php

namespace App\Services\Tickets;

use Illuminate\Database\Eloquent\Builder;
use ZipArchive;

final class TicketReportDocuments
{
    public const HEADERS = ['Ticket', 'Subject', 'Client', 'Priority', 'Status', 'Assignee', 'Created'];

    public function rows(Builder $query): array
    {
        $rows = [];
        $query->with(['customer:id,company_name', 'priority:id,name', 'status:id,name', 'assignee:id,name'])
            ->orderBy('tickets.id')->chunkById(500, function ($tickets) use (&$rows): void {
                foreach ($tickets as $ticket) $rows[] = [
                    $ticket->displayNumber(), $ticket->subject, $ticket->customer?->company_name,
                    $ticket->priority?->name, $ticket->status?->name, $ticket->assignee?->name,
                    $ticket->created_at?->toDateTimeString(),
                ];
            }, 'tickets.id', 'id');
        return $rows;
    }

    private function xml(mixed $value): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) ($value ?? ''));
        return htmlspecialchars($clean ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function create(string $format, array $rows, array $headers = self::HEADERS, string $title = 'Ticket reports'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'supportdesk-report-');
        if ($path === false) throw new \RuntimeException('Report file could not be prepared.');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Report file could not be prepared.');
        try {
            if ($format === 'xlsx') $this->xlsx($zip, $rows, $headers, $title);
            else $this->docx($zip, $rows, $headers, $title);
        } finally {
            $zip->close();
        }
        return $path;
    }

    public function createPdf(array $rows, array $headers = self::HEADERS, string $title = 'Ticket reports'): string
    {
        $widths = $headers === self::HEADERS ? [68, 180, 130, 68, 72, 128, 100]
            : [170, ...array_fill(0, count($headers) - 1, 608 / (count($headers) - 1))];
        $pageWidth = 842;
        $margin = 32;
        $pageHeight = 595;
        $makePage = function () use ($widths, $margin, $pageWidth, $headers, $title): array {
            $content = "BT /F2 18 Tf {$margin} 558 Td ".$this->pdfText($title)." Tj ET\n";
            $content .= "BT /F1 9 Tf {$margin} 542 Td ".$this->pdfText($title.' - generated '.now()->format('Y-m-d H:i'))." Tj ET\n";
            $top = 524;
            $headerLines = array_map(fn ($header, $index) => explode("\n", wordwrap($header,
                max(8, (int) floor(($widths[$index] - 8) / 4)), "\n", true)), $headers, array_keys($headers));
            $headerHeight = max(22, max(array_map('count', $headerLines)) * 10 + 8);
            $content .= "0.91 g {$margin} ".($top - $headerHeight).' '.($pageWidth - 2 * $margin)." {$headerHeight} re f\n";
            $content .= "0 g\n";
            $x = $margin;
            foreach ($headerLines as $index => $lines) {
                foreach ($lines as $lineIndex => $line) {
                    $content .= "BT /F2 8 Tf ".($x + 4).' '.($top - 15 - $lineIndex * 10).' Td '.$this->pdfText($line)." Tj ET\n";
                }
                $x += $widths[$index];
            }
            $content .= "0.65 G {$margin} ".($top - $headerHeight).' m '.($pageWidth - $margin).' '.($top - $headerHeight)." l S\n";
            return [$content, $top - $headerHeight];
        };
        $pages = [];
        [$content, $cursor] = $makePage();
        foreach ($rows as $row) {
            $wrapped = [];
            foreach ($row as $index => $value) {
                $text = (string) ($value ?? '');
                $text = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) ?: preg_replace('/[^\x20-\x7E]/', '?', $text);
                $text = preg_replace('/[\x00-\x1F\x7F]/', ' ', $text) ?? '';
                $wrapped[] = explode("\n", wordwrap($text, max(8, (int) floor(($widths[$index] - 8) / 4)), "\n", true));
            }
            $lineCount = max(array_map('count', $wrapped));
            $rowHeight = max(20, $lineCount * 10 + 8);
            if ($cursor - $rowHeight < 32) {
                $pages[] = $content;
                [$content, $cursor] = $makePage();
            }
            $top = $cursor;
            $x = $margin;
            foreach ($wrapped as $index => $lines) {
                foreach ($lines as $lineIndex => $line) {
                    $content .= 'BT /F1 8 Tf '.($x + 4).' '.($top - 13 - $lineIndex * 10).' Td '.$this->pdfText($line)." Tj ET\n";
                }
                $content .= "0.82 G {$x} ".($top - $rowHeight).' m '.($x + $widths[$index]).' '.($top - $rowHeight)." l S\n";
                $x += $widths[$index];
            }
            $cursor -= $rowHeight;
        }
        $pages[] = $content;

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        foreach ($pages as $page) {
            $pageId = max(array_keys($objects)) + 1;
            $streamId = $pageId + 1;
            $kids[] = $pageId.' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$streamId.' 0 R >>';
            $objects[$streamId] = '<< /Length '.strlen($page).">>\nstream\n".$page."\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        $path = tempnam(sys_get_temp_dir(), 'supportdesk-report-');
        if ($path === false || file_put_contents($path, $pdf) === false) throw new \RuntimeException('Report file could not be prepared.');
        return $path;
    }

    private function pdfText(string $value): string
    {
        return '<'.strtoupper(bin2hex($value)).'>';
    }

    private function xlsx(ZipArchive $zip, array $rows, array $headers, string $title): void
    {
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$this->xml($headers === self::HEADERS ? 'Tickets' : $title).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ([$headers, ...$rows] as $index => $row) {
            $xml .= '<row r="'.($index + 1).'">';
            foreach ($row as $column => $value) {
                // Inline strings cannot execute spreadsheet formulas.
                $xml .= '<c r="'.chr(65 + $column).($index + 1).'" t="inlineStr"><is><t xml:space="preserve">'.$this->xml($value).'</t></is></c>';
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');
    }

    private function docx(ZipArchive $zip, array $rows, array $headers, string $title): void
    {
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.$this->xml($title).'</w:t></w:r></w:p><w:tbl>';
        foreach ([$headers, ...$rows] as $row) {
            $xml .= '<w:tr>';
            foreach ($row as $value) $xml .= '<w:tc><w:p><w:r><w:t xml:space="preserve">'.$this->xml($value).'</w:t></w:r></w:p></w:tc>';
            $xml .= '</w:tr>';
        }
        $zip->addFromString('word/document.xml', $xml.'</w:tbl><w:sectPr/></w:body></w:document>');
    }
}

<?php

namespace App\Livewire\AgencyRegistration;

use App\Enums\PersonType;
use App\Support\UsStates;
use Illuminate\Support\Str;

/**
 * The People screen's spreadsheet (Agency spec 5.4, Option B): a template to download and the
 * rows of an uploaded CSV. The text arrives in the Livewire call (public/portal/join-csv.js
 * reads the file in the browser) and is parsed in memory; no file is written anywhere.
 */
final class PeopleCsv
{
    public const MAX_BYTES = 1024 * 1024;

    public const COLUMNS = ['type', 'first_name', 'last_name', 'email', 'npn', 'state'];

    private const BOM = "\xEF\xBB\xBF";

    /** The template's sample rows; left in an upload, they are skipped rather than enrolled. */
    public const EXAMPLES = [
        ['licensed_producer', 'Jordan', 'Lee', 'jordan.lee@youragency.com', '12345678', 'TX'],
        ['agency_employee', 'Sam', 'Rivera', 'sam.rivera@youragency.com', '', ''],
    ];

    /**
     * UTF-8 with a byte order mark, so Excel opens the names correctly.
     */
    public static function template(): string
    {
        $lines = [self::COLUMNS, ...self::EXAMPLES];

        return self::BOM.implode("\r\n", array_map(fn (array $cells) => implode(',', $cells), $lines))."\r\n";
    }

    /**
     * The people in the file, each with its spreadsheet row number (the header is row 1).
     *
     * @return array{rows: list<array{line: int, row: array<string, string>}>, examples: int}
     *
     * @throws PeopleCsvException with a message for the person uploading
     */
    public static function read(string $filename, string $content, int $maxRows): array
    {
        if (! Str::endsWith(Str::lower(trim($filename)), '.csv')) {
            throw new PeopleCsvException('Choose a .csv file. In Excel or Google Sheets, save or download the sheet as CSV.');
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw new PeopleCsvException('The file is larger than 1 MB. Split it into smaller files and upload them one after another.');
        }

        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, strlen(self::BOM));
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw new PeopleCsvException('The file is not in UTF-8. In Excel choose File > Save As > "CSV UTF-8", then upload it again.');
        }

        if (trim($content) === '') {
            throw new PeopleCsvException('The file is empty. Download the template to start.');
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        // Excel in some regions separates columns with semicolons.
        $firstLine = strtok($content, "\r\n");
        $delimiter = substr_count((string) $firstLine, ';') > substr_count((string) $firstLine, ',') ? ';' : ',';

        try {
            $header = fgetcsv($stream, null, $delimiter, '"', '');
            $columns = array_map(fn ($cell) => Str::of((string) $cell)->trim()->lower()->replace([' ', '-'], '_')->toString(), $header ?: []);
            $columns = array_map(fn (string $column) => $column === 'license_state' ? 'state' : $column, $columns);
            $missing = array_diff(self::COLUMNS, $columns);

            if ($missing !== []) {
                throw new PeopleCsvException('The first row must name the columns '.implode(', ', self::COLUMNS).'. Missing: '.implode(', ', $missing).'. Download the template to start.');
            }

            $positions = array_flip(array_reverse($columns, true));
            $rows = [];
            $examples = 0;
            $line = 1;

            while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $line++;
                $values = array_map(fn (string $column) => trim((string) ($cells[$positions[$column]] ?? '')), array_combine(self::COLUMNS, self::COLUMNS));

                if (implode('', $values) === '') {
                    continue;
                }

                if (in_array(array_values($values), self::EXAMPLES, true)) {
                    $examples++;

                    continue;
                }

                if (count($rows) >= $maxRows) {
                    throw new PeopleCsvException("The file lists more people than can still be added ({$maxRows}). Up to ".PeopleForm::MAX_HEADCOUNT.' people can enroll here; please contact ProPerX for a larger agency.');
                }

                $rows[] = ['line' => $line, 'row' => self::toPerson($values)];
            }
        } finally {
            fclose($stream);
        }

        if ($rows === [] && $examples === 0) {
            throw new PeopleCsvException('The file has no people in it. Add one person per row under the column names.');
        }

        return ['rows' => $rows, 'examples' => $examples];
    }

    /**
     * A CSV row in the People form's shape. Friendly spellings of the type ("Licensed producer",
     * "employee") and state names are understood; anything else is left for the form's rules.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private static function toPerson(array $values): array
    {
        $type = Str::of($values['type'])->lower()->replace([' ', '-'], '_')->toString();
        $type = match ($type) {
            'licensed_producer', 'producer', 'licensed_agent', 'agent' => PersonType::LicensedProducer->value,
            'agency_employee', 'employee' => PersonType::AgencyEmployee->value,
            default => $values['type'],
        };

        $state = Str::upper($values['state']);
        $state = isset(UsStates::ALL[$state]) ? $state
            : (array_search(Str::lower($values['state']), array_map(Str::lower(...), UsStates::ALL), true) ?: $values['state']);

        return [
            'type' => $type,
            'first_name' => $values['first_name'],
            'last_name' => $values['last_name'],
            'email' => $values['email'],
            'npn' => $values['npn'],
            'license_state' => $state,
        ];
    }
}

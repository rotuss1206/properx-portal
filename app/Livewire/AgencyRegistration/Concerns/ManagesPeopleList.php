<?php

namespace App\Livewire\AgencyRegistration\Concerns;

use App\Livewire\AgencyRegistration\PeopleCsv;
use App\Livewire\AgencyRegistration\PeopleCsvException;
use App\Livewire\AgencyRegistration\PeopleForm;
use Illuminate\Support\Str;

/**
 * The list of people being added, one at a time or from a CSV, with the same rules everywhere
 * (PeopleForm: type per person, NPN 7 to 10 digits, email and NPN unique, at most 500): the
 * enrollment's screen 5 (Wizard) and an enrolled agency's "Add people" (Member\AddPeople).
 *
 * The component has public PeopleForm $people, string $peopleStatus, string $importMessage and
 * bool $importOk, a focus(string $id) method, and says when the list may change, how many more
 * people fit and what happens after a change.
 */
trait ManagesPeopleList
{
    /** Whether the list may be changed now (the right screen or stage). */
    abstract protected function peopleListOpen(): bool;

    /** How many more people may be added. */
    abstract protected function peopleCapacity(): int;

    /** After the list changed (a person added, edited, removed, imported). */
    abstract protected function peopleChanged(): void;

    /** After only the form or the rows to fix changed. */
    abstract protected function peopleSaved(): void;

    /** The count after a change, e.g. "3 people." */
    abstract protected function peopleCountText(): string;

    /**
     * Adds the person in the form to the list, or saves the changes to the row being edited.
     */
    public function savePerson(): void
    {
        if (! $this->peopleListOpen()) {
            return;
        }

        $people = $this->people;
        $people->entry = PeopleForm::tidy($people->entry);
        $people->resetErrorBag('entry*');

        $adding = ! isset($people->rows[$people->editing]);

        if ($adding && $this->peopleCapacity() <= 0) {
            $people->addError('entry', $people->messages()['rows.max']);

            return;
        }

        $people->validate($people->entryRules(), $people->messages(), $people->validationAttributes());

        $name = $people->entry['first_name'].' '.$people->entry['last_name'];

        if ($adding) {
            $people->rows[] = $people->entry;
            $this->peopleStatus = "Added {$name}. ".$this->peopleCountText();
            $focus = 'people-entry-first_name';
        } else {
            $people->rows[$people->editing] = $people->entry;
            $this->peopleStatus = "Saved changes for {$name}.";
            $focus = "person-{$people->editing}-edit";
        }

        // A fixed CSV row leaves the list of rows to fix.
        if (isset($people->problems[$people->fixing])) {
            $this->peopleStatus = "Added {$name} from row {$people->problems[$people->fixing]['line']}. ".$this->peopleCountText();
            array_splice($people->problems, $people->fixing, 1);
            $focus = $people->problems === [] ? 'people-entry-first_name' : 'problem-0-fix';
        }

        // The next person's type is chosen afresh: carried over, a producer's NPN and state fields
        // stayed on screen and an employee was entered as a producer.
        $people->entry = PeopleForm::ROW;
        $people->editing = -1;
        $people->fixing = -1;
        $this->resetErrorBag('people.rows*');

        $this->peopleChanged();
        $this->focus($focus);
    }

    public function editPerson(int $index): void
    {
        if (! $this->peopleListOpen() || ! isset($this->people->rows[$index])) {
            return;
        }

        $this->people->entry = $this->people->rows[$index];
        $this->people->editing = $index;
        $this->people->fixing = -1;
        $this->people->resetErrorBag('entry*');
        $this->peopleStatus = '';
        $this->focus('people-entry-first_name');
    }

    public function removePerson(int $index): void
    {
        if (! $this->peopleListOpen() || ! isset($this->people->rows[$index])) {
            return;
        }

        $people = $this->people;
        $row = $people->rows[$index];

        array_splice($people->rows, $index, 1);

        if ($people->editing === $index) {
            $people->entry = PeopleForm::ROW;
            $people->editing = -1;
        } elseif ($people->editing > $index) {
            $people->editing--;
        }

        // Row errors are numbered by position, which has just changed.
        $this->resetErrorBag('people.rows*');
        $this->peopleStatus = "Removed {$row['first_name']} {$row['last_name']}. ".$this->peopleCountText();

        $this->peopleChanged();
        $this->focus(isset($people->rows[$index]) ? "person-{$index}-edit" : 'person-form-title');
    }

    /**
     * Leaves editing, or empties the add form.
     */
    public function clearPerson(): void
    {
        if (! $this->peopleListOpen()) {
            return;
        }

        // A row being fixed stays on the list of rows to fix.
        $fixing = $this->people->fixing;

        $this->people->entry = PeopleForm::ROW;
        $this->people->editing = -1;
        $this->people->fixing = -1;
        $this->people->resetErrorBag('entry*');
        $this->resetErrorBag('people.entry');
        $this->peopleSaved();
        $this->focus(isset($this->people->problems[$fixing]) ? "problem-{$fixing}-fix" : 'people-entry-first_name');
    }

    /**
     * The people of an uploaded CSV (read in the browser by public/portal/join-csv.js; parsed here
     * in memory, never stored). Valid rows join the list at once; rows already on it are skipped,
     * so uploading the same file again adds no one twice; the rest wait in "Rows to fix". A new
     * upload replaces the rows to fix of the previous one.
     */
    public function importPeople(string $filename, string $content): void
    {
        if (! $this->peopleListOpen()) {
            return;
        }

        $people = $this->people;
        $this->importOk = false;

        try {
            $file = PeopleCsv::read($filename, $content, max(0, $this->peopleCapacity()));
        } catch (PeopleCsvException $e) {
            $this->importMessage = $e->getMessage();
            $this->focus('csv-status');

            return;
        }

        $added = 0;
        $already = 0;
        $problems = [];

        foreach ($file['rows'] as ['line' => $line, 'row' => $row]) {
            $row = PeopleForm::tidy($row);

            if ($people->isListed($row)) {
                $already++;

                continue;
            }

            $errors = $people->rowErrors($row);

            if ($errors === []) {
                $people->rows[] = $row;
                $added++;
            } else {
                $problems[] = ['line' => $line, 'row' => $row, 'errors' => $errors];
            }
        }

        $people->problems = $problems;
        $people->fixing = -1;
        $this->resetErrorBag('people.rows*');

        $count = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);
        $this->importOk = $problems === [];
        $this->importMessage = implode(' ', array_filter([
            'Added '.$count($added, 'person', 'people').' from '.Str::limit(basename($filename), 60).'.',
            $already > 0 ? $count($already, 'row was', 'rows were').' already on your list.' : null,
            $file['examples'] > 0 ? 'The example rows from the template were left out.' : null,
            $problems !== [] ? $count(count($problems), 'row needs', 'rows need').' fixing below.' : null,
        ]));

        $this->peopleChanged();
        $this->focus($problems === [] ? 'csv-status' : 'problems-title');
    }

    /**
     * Moves a CSV row into the form, with its problems shown at the fields.
     */
    public function fixProblem(int $index): void
    {
        $problem = $this->people->problems[$index] ?? null;

        if (! $this->peopleListOpen() || $problem === null) {
            return;
        }

        $this->people->entry = $problem['row'];
        $this->people->editing = -1;
        $this->people->fixing = $index;
        $this->people->resetErrorBag('entry*');

        foreach ($problem['errors'] as $field => $message) {
            $this->people->addError("entry.{$field}", $message);
        }

        $this->focus('person-form-title');
    }

    public function skipProblem(int $index): void
    {
        if (! $this->peopleListOpen() || ! isset($this->people->problems[$index])) {
            return;
        }

        $people = $this->people;
        $line = $people->problems[$index]['line'];

        array_splice($people->problems, $index, 1);

        if ($people->fixing === $index) {
            $people->entry = PeopleForm::ROW;
            $people->fixing = -1;
            $people->resetErrorBag('entry*');
        } elseif ($people->fixing > $index) {
            $people->fixing--;
        }

        $this->peopleStatus = "Skipped row {$line}.";
        $this->peopleSaved();
        $this->focus(isset($people->problems[$index]) ? "problem-{$index}-fix" : 'people-entry-first_name');
    }

    public function skipAllProblems(): void
    {
        if (! $this->peopleListOpen() || $this->people->problems === []) {
            return;
        }

        if (isset($this->people->problems[$this->people->fixing])) {
            $this->people->entry = PeopleForm::ROW;
            $this->people->resetErrorBag('entry*');
        }

        $this->people->problems = [];
        $this->people->fixing = -1;
        $this->peopleStatus = 'Skipped the rows that needed fixing.';
        $this->peopleSaved();
        $this->focus('people-entry-first_name');
    }
}

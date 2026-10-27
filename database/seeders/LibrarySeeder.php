<?php

namespace Database\Seeders;

use App\Models\LibraryDocument;
use Database\Seeders\Support\DemoPeople;
use Illuminate\Database\Seeder;

/**
 * Fictional compliance and training library: 12 published documents and 3 drafts. Fixed data,
 * no Faker; idempotent by title. Files are written by DemoFilesSeeder.
 */
class LibrarySeeder extends Seeder
{
    /** @var list<array{string, string, string, bool}> title, category, description, published */
    private const DOCUMENTS = [
        ['Annual Compliance Calendar', 'Compliance Briefings', 'Key filing and renewal dates for licensed producers over the year.', true],
        ['Anti-Money Laundering Basics', 'Training', 'An introduction to AML obligations for life insurance producers.', true],
        ['Advertising Rules for Producers', 'Regulatory Updates', 'What may and may not appear in producer advertising.', true],
        ['Client Needs Analysis Form', 'Forms & Templates', 'A template for documenting a client needs analysis.', true],
        ['Continuing Education Overview', 'Training', 'How continuing education requirements differ between states.', true],
        ['Data Privacy Checklist', 'Compliance Briefings', 'A checklist for keeping client data private and secure.', true],
        ['Replacement Disclosure Template', 'Forms & Templates', 'A disclosure template for policy replacements.', true],
        ['State License Renewal Guide', 'Regulatory Updates', 'Step-by-step renewal guidance for resident licenses.', true],
        ['Suitability Standards Explained', 'Training', 'Best-interest and suitability standards in plain language.', true],
        ['Complaint Handling Procedure', 'Compliance Briefings', 'How to log, answer and escalate a client complaint.', true],
        ['Producer Appointment Request', 'Forms & Templates', 'A request form for carrier appointments.', true],
        ['Regulatory Update: Annuity Sales', 'Regulatory Updates', 'Recent changes to annuity sales rules.', true],
        ['Draft: Cybersecurity Policy Template', 'Forms & Templates', 'Work in progress: a cybersecurity policy for small agencies.', false],
        ['Draft: Social Media Guidelines', 'Compliance Briefings', 'Work in progress: guidelines for producer social media.', false],
        ['Draft: Ethics Refresher Course', 'Training', 'Work in progress: an ethics refresher for experienced producers.', false],
    ];

    public function run(): void
    {
        foreach (self::DOCUMENTS as $position => [$title, $category, $description, $published]) {
            LibraryDocument::query()->firstOrCreate(['title' => $title], [
                'category' => $category,
                'description' => $description,
                'file_path' => DemoPeople::pendingFile(LibraryDocument::DIRECTORY),
                'published' => $published,
                'uploaded_at' => now()->subDays(10 + $position * 9),
            ]);
        }
    }
}

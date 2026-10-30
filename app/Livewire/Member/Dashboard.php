<?php

namespace App\Livewire\Member;

use App\Member\ActivityFeed;
use App\Member\DocumentList;
use App\Member\Overview;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tabs of the member dashboard (docs/specs/member-area.md, section 3): Overview, Documents,
 * Activity and, once the catalogue is configured, Benefits. Only the tab and the document
 * filter travel to the browser; everything else is read for the signed-in member on each
 * render (no member data in public properties). The tabs are links too, so they work without
 * JavaScript (?tab=, ?filter=).
 */
class Dashboard extends Component
{
    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $filter = 'all';

    /**
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return array_filter([
            'overview' => 'Overview',
            'documents' => 'Documents',
            'activity' => 'Activity',
            'benefits' => config('portal.member_area.benefits') ? 'Benefits' : null,
        ]);
    }

    public function mount(): void
    {
        $this->normalize();
    }

    public function show(string $tab): void
    {
        $this->tab = $tab;
        $this->normalize();
    }

    public function filterBy(string $filter): void
    {
        $this->filter = $filter;
        $this->normalize();
    }

    private function normalize(): void
    {
        $this->tab = array_key_exists($this->tab, self::tabs()) ? $this->tab : 'overview';
        $this->filter = array_key_exists($this->filter, DocumentList::FILTERS) ? $this->filter : 'all';
    }

    public function render(): View
    {
        $this->normalize();
        $user = auth()->user();
        $overview = Overview::for($user);

        return view('livewire.member.dashboard', [
            'tabs' => self::tabs(),
            'overview' => $overview,
            'membership' => $this->tab === 'overview' ? $overview->membership() : null,
            'license' => $this->tab === 'overview' ? $overview->license() : null,
            'certificate' => $this->tab === 'overview' ? $overview->certificate() : null,
            'timeline' => $this->tab === 'overview' ? $overview->timeline() : null,
            'recent' => $this->tab === 'overview' ? DocumentList::recent($user) : collect(),
            'documents' => $this->tab === 'documents' ? DocumentList::for($user, $this->filter) : collect(),
            'activity' => $this->tab === 'activity' ? ActivityFeed::for($user) : collect(),
            'benefits' => config('portal.member_area.benefits'),
        ]);
    }
}

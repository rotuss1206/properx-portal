{{-- Add people to the principal's agency (G3): App\Livewire\Member\AddPeople. --}}
<x-member.layout :page="$page" :title="$title" :parent="['label' => 'My agency', 'url' => route('member.agency')]">
    <div class="ppx-agency">
        <p class="ppx-docs__lead">Add licensed producers and agency employees. You pay for the rest of the current year only; from the next renewal everyone is billed together.</p>
        <livewire:member.add-people />
    </div>

    <script src="{{ \App\Support\PortalAsset::url('join-csv.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    {{-- Focus requested by the list (ManagesPeopleList::focus), once Livewire has updated the page. --}}
    <script src="{{ \App\Support\PortalAsset::url('join-focus.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
</x-member.layout>

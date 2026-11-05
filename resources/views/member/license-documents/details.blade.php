<aside class="ppx-card ppx-docs__panel" id="ppx-document" aria-labelledby="ppx-document-title">
    <div class="ppx-docs__panel-head">
        <span class="ppx-docs__panel-icon"><x-member.icon name="license" class="ppx-docs__panel-svg" /></span>
        <div class="ppx-docs__panel-text">
            <h2 class="ppx-docs__panel-title" id="ppx-document-title">{{ $document['type'] }}</h2>
            <p class="ppx-docs__panel-name">{{ $document['name'] }}</p>
        </div>
    </div>

    <div class="ppx-docs__panel-body">
        <div class="ppx-docs__preview">
            <x-member.icon name="license" class="ppx-docs__preview-svg" />
            <span>Download the file to view it</span>
        </div>

        <dl class="ppx-docs__facts">
            <div class="ppx-docs__fact">
                <dt>Status</dt>
                <dd><x-ppx.badge :variant="$document['variant']">{{ $document['status'] }}</x-ppx.badge></dd>
            </div>
            @if ($document['reason'])
                <div class="ppx-docs__fact ppx-docs__fact--block">
                    <dt>Reason</dt>
                    <dd>{{ $document['reason'] }}</dd>
                </div>
            @endif
            <div class="ppx-docs__fact"><dt>Type</dt><dd>{{ $document['type'] }}</dd></div>
            <div class="ppx-docs__fact"><dt>Uploaded</dt><dd>{{ $document['uploadedAt']?->format('j M Y') ?? '—' }}</dd></div>
            <div class="ppx-docs__fact"><dt>Size</dt><dd>{{ $document['size'] }} · {{ $document['format'] }}</dd></div>
        </dl>

        @if ($confirmWithdraw)
            {{-- No anchor on this page's address: browsers would ignore autofocus. --}}
            <div class="ppx-docs__confirm" role="alert">
                <p class="ppx-docs__confirm-title">Withdraw this document?</p>
                <p class="ppx-docs__confirm-text">ProPerX will no longer review it and the file is deleted. To send it again you will need to upload it.</p>
                <form method="POST" action="{{ route('member.license-documents.destroy', $document['id']) }}" class="ppx-docs__actions">
                    @csrf
                    @method('DELETE')
                    <x-ppx.button type="submit" variant="danger">Withdraw document</x-ppx.button>
                    <x-ppx.button variant="secondary" :href="route('member.license-documents', ['document' => $document['id']])" autofocus>Keep it</x-ppx.button>
                </form>
            </div>
        @else
            <div class="ppx-docs__actions">
                <x-ppx.button variant="navy" icon="download" :href="$document['downloadUrl']" class="ppx-docs__download">Download</x-ppx.button>
                @if ($document['canWithdraw'])
                    <x-ppx.button variant="danger-icon" icon="trash" :href="route('member.license-documents', ['document' => $document['id'], 'withdraw' => 1])" label="Withdraw this document" />
                @endif
            </div>
        @endif
    </div>
</aside>

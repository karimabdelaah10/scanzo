{{-- The Scanzo wordmark: a QR finder-pattern mark (the label every item carries) and the name. --}}
<a class="wordmark" href="{{ route('admin.businesses.index') }}">
  <svg viewBox="0 0 30 30" aria-hidden="true">
    <rect width="30" height="30" rx="8" fill="var(--c-accent-fill)"/>
    <g fill="none" stroke="var(--c-on-accent)" stroke-width="2.4">
      <rect x="5.2" y="5.2" width="8.6" height="8.6" rx="1.6"/><rect x="16.2" y="5.2" width="8.6" height="8.6" rx="1.6"/><rect x="5.2" y="16.2" width="8.6" height="8.6" rx="1.6"/>
    </g>
    <rect x="17.5" y="17.5" width="3.4" height="3.4" rx=".8" fill="var(--c-on-accent)"/><rect x="21.6" y="21.6" width="3.2" height="3.2" rx=".8" fill="var(--c-on-accent)"/>
  </svg>
  <span>Scanzo @isset($sub)<small>{{ $sub }}</small>@endisset</span>
</a>

@if($officialProfiles !== [])
<span class="public-social-links" role="group" aria-label="POSONLY official social profiles">
    @foreach($officialProfiles as $platform => $profileUrl)
    <a href="{{ $profileUrl }}" target="_blank" rel="noopener noreferrer">{{ $platform }}</a>
    @endforeach
</span>
@endif

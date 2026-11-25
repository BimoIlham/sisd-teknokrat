@foreach($facultyLinks as $name => [$label, $activeRoute])
    @php
        $activePage = $sectionActive && request()->routeIs('pimpinan.faculties.'.$activeRoute, 'pimpinan.faculties.programs.'.$activeRoute);
    @endphp
    <a href="{{ route($sectionPrefix.$name, $sectionParams) }}"
        @if($activePage) aria-current="page" @endif
        class="flex items-center rounded-xl px-3.5 py-2 text-sm {{ $activePage ? 'bg-white font-bold text-[#7B1113]' : 'text-rose-100 hover:bg-white/10 hover:text-white' }}">{{ $label }}</a>
@endforeach

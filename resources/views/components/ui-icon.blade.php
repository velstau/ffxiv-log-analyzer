@props(['name'])
<svg {{ $attributes->class(['icon']) }} width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($name)
@case('users')
<path d="M0 0h24v24H0z" fill="none" stroke="none"></path>
<path d="M5 7a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"></path>
<path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"></path>
<path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
<path d="M21 21v-2a4 4 0 0 0 -3 -3.85"></path>
@break
@case('filter')
<path d="M0 0h24v24H0z" fill="none" stroke="none"></path>
<path d="M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.414 4.414v7l-6 2v-8.5l-4.48 -4.928a2 2 0 0 1 -.52 -1.345v-2.227"></path>
@break
@case('list-details')
<path d="M0 0h24v24H0z" fill="none" stroke="none"></path>
<path d="M13 5h8"></path>
<path d="M13 9h5"></path>
<path d="M13 15h8"></path>
<path d="M13 19h5"></path>
<path d="M3 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4"></path>
<path d="M3 15a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4"></path>
@break
@case('files')
<path d="M0 0h24v24H0z" fill="none" stroke="none"></path>
<path d="M15 3v4a1 1 0 0 0 1 1h4"></path>
<path d="M18 17h-7a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h4l5 5v7a2 2 0 0 1 -2 2"></path>
<path d="M16 17v2a2 2 0 0 1 -2 2h-7a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h2"></path>
@break
@case('layout-cards')
<path d="M0 0h24v24H0z" fill="none" stroke="none"></path>
<path d="M4 6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2l0 -12"></path>
<path d="M14 6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2l0 -6"></path>
@break
@endswitch
</svg>

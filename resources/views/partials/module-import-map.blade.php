{{-- Import map for the ES modules: every module file gets its asset() URL, so
     imports inside the modules carry the same ?v=<cache buster> as the entry
     points and a deploy never runs a new entry point against stale modules.
     It has to come before the first <script type="module"> of the page.
     See App\Services\Routing\CacheBusting\ModuleImportMap. --}}
<script type="importmap">{!! app(\App\Services\Routing\CacheBusting\ModuleImportMap::class)->toJson() !!}</script>

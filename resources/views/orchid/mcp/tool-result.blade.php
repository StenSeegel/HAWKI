@php
    $formattedActive = true;
@endphp

<div class="mcp-tool-result h-100 d-flex flex-column">
    @if(isset($title))
        <h3 class="h5 mb-3 text-muted">{{ $title }}</h3>
    @endif

    <div class="bg-white rounded shadow-sm overflow-hidden flex-grow-1 d-flex flex-column">
        <!-- Header Section -->
        @if(isset($error) && !empty($error))
            <div class="p-2 px-3 border border-danger rounded-3 mb-3 d-flex align-items-center justify-content-between" style="background-color: #fff5f5;">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-octagon-fill text-danger me-2" style="font-size: 1.1rem;"></i>
                    <span class="text-danger fw-bold">Execution failed</span>
                </div>
            </div>
        @else
            <div class="p-2 px-3 border border-success rounded-3 mb-3 d-flex align-items-center justify-content-between" style="background-color: #f6fcf8;">
                <div class="d-flex align-items-center">
                    <i class="bi bi-check-circle-fill text-success me-2" style="font-size: 1.1rem;"></i>
                    <span class="text-success fw-bold me-2">Tool executed successfully</span>
                    <span class="text-success opacity-75 small">• {{ $time ?? '0.00' }}s</span>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <div class="btn-group border rounded-3 overflow-hidden bg-white" style="padding: 2px;">
                        <button type="button" 
                                class="btn btn-sm btn-light border-0 view-toggle-btn fw-bold" 
                                data-view="formatted" 
                                style="font-size: 0.75rem; padding: 4px 12px; transition: none;">Formatted</button>
                        <button type="button" 
                                class="btn btn-sm btn-light border-0 view-toggle-btn fw-bold" 
                                data-view="json" 
                                style="font-size: 0.75rem; padding: 4px 12px; transition: none;">JSON</button>
                    </div>
                    
                    <button class="btn btn-link btn-sm text-success p-0" title="Copy to clipboard" onclick="copyResultToClipboard()">
                        <i class="bi bi-copy"></i>
                    </button>
                </div>
            </div>
        @endif

        <!-- Body Section -->
        <div class="p-4 border rounded-3 flex-grow-1 overflow-auto" style="min-height: 400px; background-color: #f1f5f9;">
            <div class="mb-3">
                <small class="text-muted fw-bold text-uppercase" style="letter-spacing: 0.05em; font-size: 0.75rem;">
                    {{ isset($error) && !empty($error) ? 'Error Message' : 'Text Response' }}
                </small>
            </div>

            @if(isset($error) && !empty($error))
                <div class="bg-white rounded-3 p-3 border border-danger shadow-sm mb-3">
                    <div class="text-danger fw-bold mb-2">Details:</div>
                    <code class="text-dark" style="white-space: pre-wrap;">{{ $error }}</code>
                </div>
            @else
                <!-- Formatted View (Universal Content Handler) -->
                <div id="mcp-view-formatted">
                    @php
                        /**
                         * Renders complex data structures recursively into a LiteLLM-like UI
                         */
                        if (!function_exists('renderMcpContent')) {
                            function renderMcpContent($content, $depth = 0) {
                                if ($depth > 10) {
                                    return '<div class="text-muted small italic">Max depth reached</div>';
                                }

                                if (is_string($content)) {
                                    $trimmed = trim($content);
                                    if (($trimmed !== '') && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                                        $decoded = json_decode($trimmed, true);
                                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                            return renderMcpContent($decoded, $depth + 1);
                                        }
                                    }

                                    // Try to detect numbered lists in long text blocks (Common in JLU/Generic Search)
                                    if (preg_match_all('/(?:^|\n)(\d+)\.\s+([^\n]+)(.*?)(?=\n\d+\.\s+|$)/s', $content, $matches, PREG_SET_ORDER)) {
                                        $intro = preg_split('/(?:^|\n)1\.\s+/', $content)[0];
                                        $introLines = array_filter(explode("\n", $intro), fn($line) => trim($line));
                                        
                                        $html = '';
                                        foreach($introLines as $introLine) {
                                            $html .= '<div class="bg-white rounded-3 p-3 border shadow-sm mb-3"><div class="text-dark" style="white-space: pre-wrap; line-height: 1.6;">' . e(trim($introLine)) . '</div></div>';
                                        }

                                        foreach ($matches as $match) {
                                            $number = $match[1];
                                            $title = trim($match[2]);
                                            $body = $match[3];
                                            
                                            $url = preg_match('/URL:\s*([^\n]+)/', $body, $m) ? trim($m[1]) : null;
                                            $snippet = preg_match('/Snippet:\s*([^\n]+)/', $body, $m) ? trim($m[1]) : null;
                                            $summary = preg_match('/Summary:\s*(.+?)(?=Content Preview:|$)/s', $body, $m) ? trim($m[1]) : null;
                                            $preview = preg_match('/Content Preview:\s*(.+?)$/s', $body, $m) ? trim($m[1]) : null;
                                            $remainingBody = preg_replace('/(URL|Snippet|Summary|Content Preview):\s*[^\n]*\n?/i', '', $body);

                                            $html .= '<div class="card mb-3 border-0 shadow-sm overflow-hidden" style="border-left: 5px solid #007bff !important; background-color: #ffffff;">';
                                            $html .= '<div class="card-body p-3">';
                                            $html .= '<h6 class="mb-1 fw-bold text-dark">' . e($number) . '. ' . e($title) . '</h6>';
                                            
                                            if($url) {
                                                $html .= '<div class="mb-2"><a href="' . e($url) . '" target="_blank" class="text-primary small text-decoration-none d-inline-flex align-items-center"><i class="bi bi-link-45deg me-1"></i>' . e($url) . '</a></div>';
                                            }
                                            if($snippet) {
                                                $html .= '<p class="mb-2 text-muted small" style="line-height: 1.5;">' . e($snippet) . '</p>';
                                            }
                                            if($summary || $preview || trim($remainingBody)) {
                                                $html .= '<div class="mt-2 text-dark small bg-light p-2 rounded border-start border-3 border-info">';
                                                if($summary) $html .= '<div class="fw-bold mb-1 small text-uppercase text-muted" style="font-size: 0.65rem;">Summary</div><p class="mb-2">' . e($summary) . '</p>';
                                                if($preview) $html .= '<div class="fw-bold mb-1 small text-uppercase text-muted" style="font-size: 0.65rem;">Preview</div><p class="mb-' . (trim($remainingBody) ? '2' : '0') . '">' . e($preview) . '</p>';
                                                if(trim($remainingBody)) $html .= '<div class="fw-bold mb-1 small text-uppercase text-muted" style="font-size: 0.65rem;">Additional Details</div><div style="white-space: pre-wrap;">' . e(trim($remainingBody)) . '</div>';
                                                $html .= '</div>';
                                            }
                                            $html .= '</div></div>';
                                        }
                                        return $html;
                                    }
                                    return '<div class="bg-white rounded-3 p-3 border shadow-sm mb-3 text-dark" style="white-space: pre-wrap; line-height: 1.6;">' . nl2br(e($content)) . '</div>';
                                }

                                if (is_array($content)) {
                                    // Handle MCP content format [{type: 'text', text: '...'}]
                                    if (!empty($content) && isset($content[0]['type'])) {
                                        $html = '';
                                        foreach ($content as $item) {
                                            if (isset($item['type']) && $item['type'] === 'text') {
                                                $html .= renderMcpContent($item['text'], $depth + 1);
                                            } elseif (isset($item['type']) && $item['type'] === 'image') {
                                                $html .= '<div class="bg-white rounded-3 p-3 border shadow-sm mb-3 text-center"><i class="bi bi-image" style="font-size: 2rem; color: #dee2e6;"></i><p class="text-muted small mt-2">Image content block</p></div>';
                                            }
                                        }
                                        return $html;
                                    }

                                    // Handle search results format (Tavily/Generic) {results: [...], images: [...]}
                                    if (isset($content['results']) && is_array($content['results'])) {
                                        $html = '';
                                        if (isset($content['query'])) {
                                            $html .= '<div class="alert alert-light border mb-3 rounded-3 p-2 shadow-xs bg-white"><small class="text-muted">Results for <span class="text-dark fw-bold">"' . e($content['query']) . '"</span>:</small></div>';
                                        }

                                        // Render Images if present (Tavily)
                                        if (isset($content['images']) && is_array($content['images']) && !empty($content['images'])) {
                                            $html .= '<div class="mb-3 d-flex flex-wrap gap-2">';
                                            foreach ($content['images'] as $img) {
                                                if (isset($img['url'])) {
                                                    $html .= '<a href="' . e($img['url']) . '" target="_blank" class="d-block border rounded overflow-hidden shadow-sm bg-white" style="width: 120px;" title="' . e($img['description'] ?? '') . '">';
                                                    $html .= '<img src="' . e($img['url']) . '" class="w-100 h-100" style="object-fit: cover; aspect-ratio: 1/1;" onerror="this.src=\'https://placehold.co/120?text=No+Preview\'">';
                                                    $html .= '</a>';
                                                }
                                            }
                                            $html .= '</div>';
                                        }

                                        foreach ($content['results'] as $index => $item) {
                                            $html .= '<div class="card mb-3 border-0 shadow-sm overflow-hidden" style="border-left: 5px solid #007bff !important; background-color: #ffffff;">';
                                            $html .= '<div class="card-body p-3">';
                                            $html .= '<h6 class="mb-1 fw-bold text-dark">' . ($index + 1) . '. ' . e($item['title'] ?? 'No Title') . '</h6>';
                                            
                                            if (isset($item['url'])) {
                                                $html .= '<div class="mb-2"><a href="' . e($item['url']) . '" target="_blank" class="text-primary small text-decoration-none d-inline-flex align-items-center"><i class="bi bi-link-45deg me-1"></i>' . e($item['url']) . '</a></div>';
                                            }

                                            // Description/Snippet/Content
                                            $snippet = $item['snippet'] ?? $item['content'] ?? null;
                                            if ($snippet) {
                                                $html .= '<p class="mb-2 text-muted small" style="line-height: 1.5;">' . e($snippet) . '</p>';
                                            }

                                            // Extended content handles (Summary/Preview)
                                            if (isset($item['summary']) || isset($item['content_preview'])) {
                                                $html .= '<div class="mt-2 text-dark small bg-light p-2 rounded border-start border-3 border-info">';
                                                $html .= '<div class="fw-bold mb-1 small text-uppercase text-muted" style="font-size: 0.65rem;">Content</div>' . e($item['summary'] ?? $item['content_preview']) . '</div>';
                                            }
                                            $html .= '</div></div>';
                                        }
                                        return $html;
                                    }

                                    // Generic Array/Object Deconstruction (Iterative)
                                    $html = '';
                                    foreach ($content as $key => $value) {
                                        $html .= '<div class="card mb-2 border-0 shadow-sm overflow-hidden bg-white">';
                                        $html .= '<div class="card-body p-2 px-3"><div class="row align-items-center">';
                                        $html .= '<div class="col-md-2 py-1"><small class="text-muted fw-bold text-uppercase" style="font-size: 0.65rem;">' . (is_numeric($key) ? '#' . ($key + 1) : e($key)) . '</small></div>';
                                        $html .= '<div class="col-md-10 py-1">';
                                        if (is_array($value) || is_object($value)) {
                                            // If it's a small object, try to render it inline or nested, else show JSON
                                            if (count((array)$value) < 5 && $depth < 2) {
                                                $html .= renderMcpContent($value, $depth + 1);
                                            } else {
                                                $html .= '<pre class="bg-light p-2 mb-0 border small rounded" style="max-height: 200px; overflow: auto; white-space: pre-wrap;">' . e(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                                            }
                                        } else {
                                            $html .= '<div class="text-dark">' . e($value) . '</div>';
                                        }
                                        $html .= '</div></div></div></div>';
                                    }
                                    return $html;
                                }
                                return '<div class="text-muted italic">No displayable content</div>';
                            }
                        }
                    @endphp

                    {!! renderMcpContent($data) !!}
                </div>

                <!-- JSON View (Raw and stable) -->
                <div id="mcp-view-json" style="display: none;">
                    <div class="bg-white rounded-3 p-3 border shadow-sm h-100 overflow-auto">
                        <pre class="mb-0 text-dark small" 
                             style="white-space: pre-wrap; word-break: break-all; font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', 'Consolas', monospace; font-size: 0.85rem; border: none; background: transparent; padding: 0;">{{ $json ?? $result }}</pre>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

<script>
(function() {
    function copyResultToClipboard() {
        const result = {!! json_encode($json ?? $result ?? '') !!};
        if (!result || result === '') return;
        
        navigator.clipboard.writeText(result).then(() => {
            if (typeof window.Toast !== 'undefined') {
                window.Toast.success('Copied to clipboard');
            } else {
                alert('Copied to clipboard');
            }
        });
    }

    // Attach to window so it's accessible from the onclick attribute
    window.copyResultToClipboard = copyResultToClipboard;

    document.querySelectorAll('.view-toggle-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const view = this.getAttribute('data-view');
            
            // Toggle active state
            document.querySelectorAll('.view-toggle-btn').forEach(b => {
                b.classList.remove('active', 'bg-success-soft');
                b.classList.add('text-muted');
            });
            this.classList.add('active', 'bg-success-soft');
            this.classList.remove('text-muted');
            
            // Toggle visibility
            if (view === 'formatted') {
                document.getElementById('mcp-view-formatted').style.display = 'block';
                document.getElementById('mcp-view-json').style.display = 'none';
            } else {
                document.getElementById('mcp-view-formatted').style.display = 'none';
                document.getElementById('mcp-view-json').style.display = 'block';
            }
        });
    });

    // Initialize initial state
    const initialBtn = document.querySelector('.view-toggle-btn[data-view="formatted"]');
    if (initialBtn) {
        initialBtn.classList.add('active', 'bg-success-soft');
        initialBtn.classList.remove('text-muted');
    }
})();
</script>

<style>
    .bg-success-soft {
        background-color: rgba(40, 167, 69, 0.1) !important;
        color: #28a745 !important;
    }
    .shadow-xs {
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
</style>

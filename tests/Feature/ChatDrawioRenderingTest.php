<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A draw.io diagram a model writes is shown as a diagram, drawn by HAWKI's own
 * draw.io instance, and can be edited there - nothing goes to diagrams.net.
 * Pinned on the delivered files, as the other code box tests are.
 */
class ChatDrawioRenderingTest extends TestCase
{
    public function test_a_drawio_block_is_a_diagram_kind_and_raw_xml_is_fenced(): void
    {
        $js = file_get_contents(public_path('js/syntax_modifier.js'));

        $this->assertStringContainsString("if (['drawio', 'xml'].includes(lang) && typeof isCompleteDrawio === 'function' && isCompleteDrawio(block.textContent)) {\n    return 'drawio';", $js);
        $this->assertStringContainsString("'\\n```drawio\\n' + match.trim() + '\\n```\\n'", $js);
        $this->assertStringContainsString("hljs.registerAliases(['drawio'], { languageName: 'xml' });", $js);

        // Drawn only for complete messages, like mermaid; the source is what the download saves.
        $this->assertStringContainsString("if (kind === 'svg' || (kind !== null && renderDiagrams)) {", $js);
        $this->assertStringContainsString("preview.dataset.downloadName = 'diagram.drawio';", $js);
        $this->assertStringContainsString("actions.prepend(buildDiagramEditButton(wrapper, block));", $js);
    }

    public function test_viewer_and_editor_come_from_hawkis_own_instance(): void
    {
        $js = file_get_contents(public_path('js/drawio_functions.js'));

        $this->assertStringContainsString("const DRAWIO_PATH = '/drawio';", $js);
        $this->assertStringNotContainsString('diagrams.net', str_replace(['viewer.diagrams.net.', 'diagrams.net for'], '', $js));
        foreach (['STENCIL_PATH', 'SHAPES_PATH', 'IMAGE_PATH', 'DRAW_MATH_URL', 'mxBasePath', 'PROXY_URL'] as $path) {
            $this->assertStringContainsString('window.'.$path.' = base', $js, $path.' would default to diagrams.net');
        }

        // The editor runs in an iframe over the embed protocol; its own save and
        // exit buttons stay hidden, the header decides what save means.
        $this->assertStringContainsString('embed=1&proto=json', $js);
        $this->assertStringContainsString('noSaveBtn=1&noExitBtn=1', $js);
        $this->assertStringContainsString("post({ action: 'load', xml: String(xml)", $js);
        $this->assertStringContainsString("new File([String(xml)], name, { type: 'application/xml' })", $js);

        $this->assertStringContainsString("asset('js/drawio_functions.js')", file_get_contents(resource_path('views/layouts/home.blade.php')));
    }

    public function test_the_stack_ships_drawio_and_nginx_proxies_it(): void
    {
        foreach (['dev', 'staging', 'prod'] as $profile) {
            $compose = file_get_contents(base_path("_docker/compose/docker-compose.{$profile}.yml"));
            $this->assertStringContainsString('image: jgraph/drawio:28.2.5', $compose, $profile);
            $this->assertStringContainsString('DRAWIO_SELF_CONTAINED=1', $compose, $profile);

            $nginx = file_get_contents(base_path("_docker/nginx/nginx.template.{$profile}"));
            $this->assertStringContainsString('location ^~ /drawio/ {', $nginx, $profile);
            $this->assertStringContainsString('proxy_pass http://drawio:8080/;', $nginx, $profile);
        }
    }

    public function test_a_drawio_file_can_be_attached_and_is_labelled(): void
    {
        $this->assertStringContainsString("'application/vnd.jgraph.mxfile',", file_get_contents(public_path('js/attachment_handler.js')));
        $this->assertStringContainsString("return 'text';", file_get_contents(public_path('js/file_manager.js')));
        $this->assertStringContainsString("case('text'):\n            return 'file_upload';", file_get_contents(public_path('js/model_list_filtering.js')));
        $this->assertFileExists(public_path('img/fileformat/txt.svg'));

        foreach (['en_US', 'de_DE'] as $language) {
            $texts = json_decode(file_get_contents(resource_path("language/{$language}.json")), true);
            foreach (['EditDiagram', 'DownloadDrawio', 'AttachToMessage', 'DiagramEditor'] as $key) {
                $this->assertNotEmpty($texts[$key] ?? '', $language.' is missing '.$key);
            }
        }

        $this->assertStringContainsString('content: "draw.io" !important;', file_get_contents(public_path('css/hljs_custom.css')));
    }
}

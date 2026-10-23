<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\HtmlRenderer;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

class TaskMarkdown
{
    public const OPTIONS = ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 100, 'renderer' => ['soft_break' => "<br>\n"]];

    private function environment(): Environment
    {
        return (new Environment(self::OPTIONS))
            ->addExtension(new CommonMarkCoreExtension)
            ->addExtension(new GithubFlavoredMarkdownExtension);
    }

    public function render(?string $markdown, bool $editable = false): string
    {
        $environment = $this->environment();
        $environment->addRenderer(TaskListItemMarker::class, new class($editable, hash('sha256', $markdown ?? '')) implements NodeRendererInterface
        {
            public function __construct(private bool $editable, private string $revision) {}

            public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
            {
                TaskListItemMarker::assertInstanceOf($node);
                $line = $node->parent()->getStartLine();
                $attributes = ['type' => 'checkbox', 'aria-label' => 'Item do checklist, linha '.$line];
                if ($node->isChecked()) {
                    $attributes['checked'] = '';
                }
                if ($this->editable) {
                    $attributes['wire:change'] = "toggleChecklist({$line}, '{$this->revision}', \$event.target.checked)";
                    $attributes['wire:loading.attr'] = 'disabled';
                    $attributes['wire:target'] = 'toggleChecklist';
                } else {
                    $attributes['disabled'] = '';
                }

                return new HtmlElement('input', $attributes, '', true);
            }
        }, 100);

        return (string) (new HtmlRenderer($environment))->renderDocument((new MarkdownParser($environment))->parse($markdown ?? ''));
    }

    public function toggle(string $markdown, int $line, bool $checked): string
    {
        $document = (new MarkdownParser($this->environment()))->parse($markdown);
        $valid = false;
        foreach ($document->iterator() as $node) {
            if ($node instanceof TaskListItemMarker && $node->parent()->getStartLine() === $line) {
                $valid = true;
                break;
            }
        }
        $lines = preg_split('/(?<=\n)/', $markdown);
        if (! $valid || ! isset($lines[$line - 1]) || ! preg_match('/^(.*?(?:[-+*]|[0-9]+[.)])\h+)\[[ xX]\]/', $lines[$line - 1], $match)) {
            throw ValidationException::withMessages(['checklist' => 'Item de checklist inválido.']);
        }
        $lines[$line - 1] = substr_replace($lines[$line - 1], $checked ? 'x' : ' ', strlen($match[1]) + 1, 1);

        return implode('', $lines);
    }
}

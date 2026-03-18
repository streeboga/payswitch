<?php

namespace Dedoc\Scramble\DocumentTransformers;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Tag;

class AddTagGroups implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        if (empty($document->tags)) {
            return;
        }

        $groups = $this->buildTagGroups($document->tags);

        if (count($groups) > 1) {
            $document->setExtensionProperty('tagGroups', $groups);
        }
    }

    /**
     * @param  Tag[]  $tags
     * @return array<int, array{name: string, tags: string[]}>
     */
    private function buildTagGroups(array $tags): array
    {
        $grouped = [];

        foreach ($tags as $tag) {
            $groupName = $this->extractGroupName($tag->name);

            if (! isset($grouped[$groupName])) {
                $grouped[$groupName] = [];
            }

            $grouped[$groupName][] = $tag->name;
        }

        $result = [];
        foreach ($grouped as $groupName => $tagNames) {
            $result[] = [
                'name' => $groupName,
                'tags' => $tagNames,
            ];
        }

        return $result;
    }

    /**
     * Extract top-level group name from tag name.
     *
     * "Admin > Connectors"    → "Admin"
     * "Dashboard > Payments"  → "Dashboard"
     * "Payments"              → default group from config or "API"
     */
    private function extractGroupName(string $tagName): string
    {
        if (str_contains($tagName, '>')) {
            return trim(explode('>', $tagName, 2)[0]);
        }

        return config('scramble.ui.default_tag_group', 'API');
    }
}

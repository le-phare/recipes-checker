<?php

/*
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'generate:flex-endpoint', description: 'Generates the json files required by Flex')]
class GenerateFlexEndpointCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('repository', InputArgument::REQUIRED, 'The name of the GitHub repository')
            ->addArgument('source_branch', InputArgument::REQUIRED, 'The source branch of recipes')
            ->addArgument('flex_branch', InputArgument::REQUIRED, 'The branch of the target Flex endpoint')
            ->addArgument('output_directory', InputArgument::REQUIRED, 'The directory where generated files should be stored')
            ->addArgument('versions_json', InputArgument::OPTIONAL, 'The file where versions of Symfony are described')
            ->addOption('contrib');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repository = $input->getArgument('repository');
        $sourceBranch = $input->getArgument('source_branch');
        $flexBranch = $input->getArgument('flex_branch');
        $outputDir = $input->getArgument('output_directory');
        $versionsJson = $input->getArgument('versions_json');
        $contrib = $input->getOption('contrib');

        $aliases = $recipes = $recipeConflicts = $versions = [];

        if ($versionsJson) {
            $versions = json_decode(file_get_contents($versionsJson), true);

            foreach ($versions['splits'] as $package => $v) {
                if (0 === strpos($package, 'symfony/') && '-pack' !== substr($package, -5)) {
                    $alias = substr($package, 8);
                    $aliases[$alias] = $package;
                    $aliases[str_replace('-', '', $alias)] = $package;
                }
            }
        }

        // stdin usually generated by `git ls-tree HEAD */*/*`

        while (false !== $line = fgets(\STDIN)) {
            [$tree, $package] = explode("\t", trim($line));
            [,, $tree] = explode(' ', $tree);

            if (!file_exists($package . '/manifest.json')) {
                continue;
            }

            $manifest = json_decode(file_get_contents($package . '/manifest.json'), true);
            $version = substr($package, 1 + strrpos($package, '/'));
            $package = substr($package, 0, -1 - \strlen($version));

            foreach ($manifest['aliases'] ?? [] as $alias) {
                $aliases[$alias] = $package;
                $aliases[str_replace('-', '', $alias)] = $package;
            }

            if (0 === strpos($package, 'symfony/') && '-pack' !== substr($package, -5)) {
                $alias = substr($package, 8);
                $aliases[$alias] = $package;
                $aliases[str_replace('-', '', $alias)] = $package;
            }

            if ($this->generatePackageJson($package, $version, $manifest, $tree, $outputDir)) {
                $recipes[$package][] = $version;
                usort($recipes[$package], 'strnatcmp');

                if (isset($manifest['conflict'])) {
                    uksort($manifest['conflict'], 'strnatcmp');
                    $recipeConflicts[$package][$version] = $manifest['conflict'];
                    uksort($recipeConflicts[$package], 'strnatcmp');
                }
            }
        }

        ksort($aliases, \SORT_NATURAL);
        ksort($recipes, \SORT_NATURAL);
        ksort($recipeConflicts, \SORT_NATURAL);

        file_put_contents($outputDir . '/index.json', json_encode([
            'aliases' => $aliases,
            'recipes' => $recipes,
            'recipe-conflicts' => $recipeConflicts,
            'versions' => $versions,
            'branch' => $sourceBranch,
            'is_contrib' => $contrib,
            '_links' => match (true) {
                // Quick and dirty way to mimic Github using gitlab url format
                1 === preg_match('/^(?<scheme>https?):\/\/(?:(?<credentials>.+)@)?(?<host>[^\/]+)\/(?<path>[^\?]+)$/', $repository, $parts) => [
                    'repository' => sprintf('%s://%s/%s', $parts['scheme'], $parts['host'], preg_replace('/(\.git)$/', '', (ltrim($parts['path'], '/')))),
                    'origin_template' => sprintf('{package}:{version}@%s/%s:%s', $parts['host'], preg_replace('/(\.git)$/', '', (ltrim($parts['path'], '/'))), $sourceBranch),
                    'recipe_template' => sprintf('%s://%s/%s/-/raw/%s/{package_dotted}.{version}.json', $parts['scheme'], $parts['host'], preg_replace('/(\.git)$/', '', (ltrim($parts['path'], '/'))), $flexBranch),
                    'recipe_template_relative' => sprintf('{package_dotted}.{version}.json', $repository, $flexBranch),
                    'archived_recipes_template' => sprintf('%s://%s/%s/-/raw/%s/archived/{package_dotted}/{ref}.json', $parts['scheme'], $parts['host'], preg_replace('/(\.git)$/', '', (ltrim($parts['path'], '/'))), $flexBranch),
                    'archived_recipes_template_relative' => sprintf('archived/{package_dotted}/{ref}.json', $repository, $flexBranch),
                ],
                default => [
                    'repository' => sprintf('github.com/%s', $repository),
                    'origin_template' => sprintf('{package}:{version}@github.com/%s:%s', $repository, $sourceBranch),
                    'recipe_template' => sprintf('https://raw.githubusercontent.com/%s/%s/{package_dotted}.{version}.json', $repository, $flexBranch),
                    'recipe_template_relative' => sprintf('{package_dotted}.{version}.json', $repository, $flexBranch),
                    'archived_recipes_template' => sprintf('https://raw.githubusercontent.com/%s/%s/archived/{package_dotted}/{ref}.json', $repository, $flexBranch),
                    'archived_recipes_template_relative' => sprintf('archived/{package_dotted}/{ref}.json', $repository, $flexBranch),
                ]
            },
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");

        return 0;
    }

    private function generatePackageJson(string $package, string $version, array $manifest, string $tree, string $outputDir): bool
    {
        unset($manifest['aliases']);

        $files = [];
        $it = new \RecursiveDirectoryIterator($package . '/' . $version);
        $it->setFlags($it::SKIP_DOTS | $it::FOLLOW_SYMLINKS | $it::UNIX_PATHS);

        foreach (new \RecursiveIteratorIterator($it) as $path => $file) {
            $file = substr($path, 1 + \strlen($package . '/' . $version));
            if (is_dir($path) || 'manifest.json' === $file) {
                continue;
            }
            if ('post-install.txt' === $file) {
                $manifest['post-install-output'] = explode("\n", rtrim(str_replace("\r", '', file_get_contents($path)), "\n"));
                continue;
            }
            if ('Makefile' === $file) {
                $manifest['makefile'] = explode("\n", rtrim(str_replace("\r", '', file_get_contents($path)), "\n"));
                continue;
            }
            $contents = file_get_contents($path);
            $files[$file] = [
                'contents' => preg_match('//u', $contents) ? explode("\n", $contents) : base64_encode($contents),
                'executable' => is_executable($path),
            ];
        }

        if (!$manifest) {
            return false;
        }

        ksort($files, \SORT_NATURAL);

        $contents = json_encode(
            [
                'manifests' => [
                    $package => [
                        'manifest' => $manifest,
                        'files' => $files,
                        'ref' => $tree,
                    ],
                ],
            ],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES
        ) . "\n";
        file_put_contents(sprintf('%s/%s.%s.json', $outputDir, str_replace('/', '.', $package), $version), $contents);

        // save another version for the archives
        $archivedPath = sprintf('%s/archived/%s/%s.json', $outputDir, str_replace('/', '.', $package), $tree);
        if (!file_exists(\dirname($archivedPath))) {
            mkdir(\dirname($archivedPath), 0777, true);
        }
        file_put_contents($archivedPath, $contents);

        return true;
    }
}

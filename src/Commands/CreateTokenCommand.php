<?php

declare(strict_types=1);

namespace Naf\MCP\Commands;

use DateTimeImmutable;
use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use Throwable;

use function Naf\MCP\tokens;

final class CreateTokenCommand extends AbstractCommand
{
    public const string NAME = 'mcp:token:create';

    protected function configure(): void
    {
        $this
            ->setTitle('Create MCP Token')
            ->setDescription('Create a Bearer token for the MCP Streamable HTTP endpoint.')
            ->addArgument('name', true)
            ->addOption('scope', 's', true)
            ->addOption('expires', 'e', true)
            ->addOption('help');
    }

    public function run(Input $input, Output $output): int
    {
        if ($input->getOption('help') === true) {
            $this->showHelp($output);
            $output->writeLine('  <comment>Usage:</comment>');
            $output->writeLine('    vendor/bin/naf mcp:token:create "Local Codex" --scope "*"');
            $output->writeLine('    vendor/bin/naf mcp:token:create "Articles" --scope articles:read --scope articles:write --expires 2026-12-31');
            $output->writeLine('');

            return self::SUCCESS;
        }

        $name      = trim((string) ($input->getArgument('name') ?? 'MCP token'));
        $scopes    = $this->normalizeScopes($input->getOption('scope'));
        $expiresAt = $this->normalizeExpiresAt($input->getOption('expires'));

        if ($expiresAt === false) {
            $output->writeLine('Invalid expiration date. Use something parseable like 2026-12-31 or +30 days.', 'error');

            return self::ERROR;
        }

        $created = tokens()->create($name, $scopes, $expiresAt);

        $output->writeLine('MCP token created successfully.', 'ok');
        $output->writeLine('');
        $output->writeLine('  Token:      ' . $created->plainToken);
        $output->writeLine('  ID:         ' . $created->record->id);
        $output->writeLine('  Name:       ' . $created->record->name);
        $output->writeLine('  Scopes:     ' . implode(', ', $created->record->scopes));
        $output->writeLine('  Expires at: ' . ($created->record->expiresAt ?? 'never'));
        $output->writeLine('');
        $output->writeLine('Store the token now. Only its hash is persisted.', 'warning');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeScopes(string|array|bool|null $value): array
    {
        if ($value === null || $value === false) {
            return ['*'];
        }

        $values = is_array($value) ? $value : [$value];
        $scopes = [];

        foreach ($values as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            foreach (explode(',', $entry) as $scope) {
                $scope = trim($scope);
                if ($scope !== '') {
                    $scopes[] = $scope;
                }
            }
        }

        return array_values(array_unique($scopes)) ?: ['*'];
    }

    private function normalizeExpiresAt(string|array|bool|null $value): DateTimeImmutable|false|null
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(trim($value));
        } catch (Throwable) {
            return false;
        }
    }
}

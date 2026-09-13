<?php

declare(strict_types=1);

namespace Naf\MCP\Commands;

use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use function Naf\MCP\tokens;

final class ListTokensCommand extends AbstractCommand
{
    public const string NAME = 'mcp:token:list';

    protected function configure(): void
    {
        $this
            ->setTitle('List MCP Tokens')
            ->setDescription('List stored MCP Bearer tokens without exposing token secrets.')
            ->addOption('help');
    }

    public function run(Input $input, Output $output): int
    {
        if ($input->getOption('help') === true) {
            $this->showHelp($output);
            $output->writeLine('  <comment>Usage:</comment>');
            $output->writeLine('    vendor/bin/nix mcp:token:list');
            $output->writeLine('');
            return self::SUCCESS;
        }

        $records = tokens()->all();
        if ($records === []) {
            $output->writeLine('No MCP tokens found.');
            return self::SUCCESS;
        }

        foreach ($records as $record) {
            $status = $record->isActive() ? 'active' : 'inactive';
            $output->writeLine($record->id . ' [' . $status . ']');
            $output->writeLine('  Name:       ' . $record->name);
            $output->writeLine('  Scopes:     ' . implode(', ', $record->scopes));
            $output->writeLine('  Created at: ' . $record->createdAt);
            $output->writeLine('  Expires at: ' . ($record->expiresAt ?? 'never'));
            $output->writeLine('  Last used:  ' . ($record->lastUsedAt ?? 'never'));
            $output->writeLine('  Revoked at: ' . ($record->revokedAt ?? 'not revoked'));
            $output->writeLine('');
        }

        return self::SUCCESS;
    }
}

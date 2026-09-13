<?php

declare(strict_types=1);

namespace Naf\MCP\Commands;

use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use function Naf\MCP\tokens;

final class RevokeTokenCommand extends AbstractCommand
{
    public const string NAME = 'mcp:token:revoke';

    protected function configure(): void
    {
        $this
            ->setTitle('Revoke MCP Token')
            ->setDescription('Revoke a stored MCP Bearer token by id.')
            ->addArgument('id')
            ->addOption('help');
    }

    public function run(Input $input, Output $output): int
    {
        if ($input->getOption('help') === true) {
            $this->showHelp($output);
            $output->writeLine('  <comment>Usage:</comment>');
            $output->writeLine('    vendor/bin/naf mcp:token:revoke tok_...');
            $output->writeLine('');
            return self::SUCCESS;
        }

        $id = trim((string)$input->getArgument('id'));
        if ($id === '') {
            $output->writeLine('Token id is required.', 'error');
            return self::ERROR;
        }

        if (!tokens()->revoke($id)) {
            $output->writeLine('Token not found: ' . $id, 'error');
            return self::ERROR;
        }

        $output->writeLine('MCP token revoked: ' . $id, 'ok');

        return self::SUCCESS;
    }
}

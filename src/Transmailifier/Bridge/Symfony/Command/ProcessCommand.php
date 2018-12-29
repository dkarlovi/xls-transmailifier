<?php

declare(strict_types=1);

/*
 * This file is part of the transmailifier project.
 *
 * (c) Dalibor Karlović <dalibor@flexolabs.io>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Dkarlovi\Transmailifier\Bridge\Symfony\Command;

use Dkarlovi\Transmailifier\Processor;
use Dkarlovi\Transmailifier\Transaction;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProcessCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'process';

    /**
     * @var Processor
     */
    private $processor;

    public function __construct(Processor $processor)
    {
        parent::__construct('process');

        $this->setDescription('Process a ledger, mark transactions as processed, send a CSV to specified addresses');

        $this->processor = $processor;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::REQUIRED, 'Processing profile to use')
            ->addArgument('path', InputArgument::REQUIRED, 'File to processUnprocessedTransactions');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        /** @var string $path */
        $path = $input->getArgument('path');
        /** @var string $profile */
        $profile = $input->getArgument('profile');
        $ledger = $this->processor->read(new \SplFileObject($path), $profile);

        // TODO: header with profile name, where to send, etc

        $unprocessedTransactions = $this->processor->filterProcessedTransactions($ledger);
        $unprocessedTransactionsCount = \count($unprocessedTransactions);

        $style = new SymfonyStyle($input, $output);
        if (0 === $unprocessedTransactionsCount) {
            $style->success('All the transactions have already been processed.');

            return;
        }

        $formatter = new \NumberFormatter('hr', \NumberFormatter::CURRENCY);
        $currencyFormatter = function (float $amount) use ($formatter, $ledger) {
            return $formatter->formatCurrency($amount, $ledger->getCurrency());
        };

        $uncategorizedTransactions = [];
        foreach ($unprocessedTransactions as $unprocessedTransaction) {
            if (false === $unprocessedTransaction->hasCategory()) {
                $uncategorizedTransactions[] = $unprocessedTransaction;
            }
        }

        $this->previewUncategorizedTransactions($output, $style, $currencyFormatter, $uncategorizedTransactions);
        $uncategorizedTransactionsCount = \count($uncategorizedTransactions);

        if (0 === $uncategorizedTransactionsCount || true === $style->confirm(\sprintf('Proceed with %1$d uncategorized transactions?', $uncategorizedTransactionsCount))) {
            $this->previewUnprocessedTransactions($output, $style, $currencyFormatter, $unprocessedTransactions);

            if (true === $style->confirm(\sprintf('Process these %1$d transactions?', $unprocessedTransactionsCount))) {
                $this->processor->processUnprocessedTransactions($ledger);

                $style->success(\sprintf('Successfully processed %1$d new transactions.', $unprocessedTransactionsCount));
            } else {
                $style->warning('Processing aborted.');
            }
        } else {
            $style->warning('Processing aborted.');
        }
    }

    private function previewUncategorizedTransactions(OutputInterface $output, StyleInterface $style, callable $formatter, array $uncategorizedTransactions): void
    {
        $uncategorizedTransactionsCount = \count($uncategorizedTransactions);

        if ($uncategorizedTransactionsCount > 0) {
            $style->note(\sprintf('Found %1$d uncategorized transactions', $uncategorizedTransactionsCount));

            $this->renderTransactions($output, $formatter, $uncategorizedTransactions);
        }
    }

    private function previewUnprocessedTransactions(OutputInterface $output, StyleInterface $style, callable $formatter, array $unprocessedTransactions, int $unprocessedDisplayLimit = 10): void
    {
        $unprocessedTransactionsCount = \count($unprocessedTransactions);
        $style->note(\sprintf('Found %1$d new transactions', $unprocessedTransactionsCount));

        $transactionsToDisplay = $unprocessedTransactions;
        if ($unprocessedTransactionsCount > $unprocessedDisplayLimit) {
            $transactionsToDisplay = \array_slice($unprocessedTransactions, -$unprocessedDisplayLimit);

            $style->note(\sprintf('(displaying latest %1$d transactions)', $unprocessedDisplayLimit));
        }

        $this->renderTransactions($output, $formatter, $transactionsToDisplay);
    }

    /**
     * @param Transaction[] $transactions
     */
    private function renderTransactions(OutputInterface $output, callable $formatter, array $transactions): void
    {
        $rightAligned = new TableStyle();
        $rightAligned->setPadType(STR_PAD_LEFT);

        $table = new Table($output);
        $table->setStyle('box');
        $table->setHeaders(['Date', 'Amount', 'New state', 'Category', 'Payee', 'Note']);
        $table->setColumnWidths([10, 15, 15, 20]);
        $table->setColumnStyle(1, $rightAligned);
        $table->setColumnStyle(2, $rightAligned);

        foreach ($transactions as $transaction) {
            $table->addRow(
                [
                    $transaction->getTime()->format('Y-m-d'),
                    $formatter($transaction->getAmount()),
                    $formatter($transaction->getState()),
                    $transaction->getCategory(),
                    $transaction->getPayee(),
                    $transaction->getNote(),
                ]
            );
        }
        $table->render();
    }
}
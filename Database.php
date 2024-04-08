<?php

namespace FpDbTest;

use Exception;
use mysqli;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        $queryParts = explode('?', $query);
        $builtQuery = $queryParts[0];
        $queryPartsCount = count($queryParts);

        for ($i = 1; $i < $queryPartsCount; $i++) {
            $specifier = $this->getSpecifier($queryParts[$i]);
            $value = $args[$i - 1] ?? null;

            $builtQuery .= $this->getReplacedArg($specifier, $value) . $this->getQueryPart($specifier, $queryParts[$i]);
        }

        $builtQuery .= $this->finalizeQuery($queryParts[$queryPartsCount - 1]);

        return $this->removeSkippedParts($builtQuery, $args);
    }

    /**
     * @throws Exception
     */
    private function getReplacedArg(string $specifier, $argument): string
    {
        switch ($specifier) {
            case '?d':
                $modifiedArg = (int) $argument;
                break;
            case '?f':
                $modifiedArg = (float)$argument;
                break;
            case '?a':
                if (!is_array($argument)) {
                    throw new Exception("?a argument should be an array");
                }
                $modifiedArg = $this->processArrayArgument($argument);
                break;
            case '?#':
                $modifiedArg = $this->processArrayKeys($argument);
                break;
            default:
                $modifiedArg = $this->processDefaultArgument($argument);
                break;
        }

        return trim($modifiedArg);
    }

    private function getSpecifier(string $part): string
    {
        return '?' . substr($part, 0, 1);
    }

    private function processArrayArgument(array $argument): string
    {
        $isAssociative = array_keys($argument) !== range(0, count($argument) - 1);
        if ($isAssociative) {
            $argument = implode(', ', array_map(function($key, $value) {
                return "`$key` " . '= ' . (is_string($value) ? "'" . $value . "'" : ($value === null ? 'NULL' : $value));
            }, array_keys($argument), $argument));
        } else {
            $argument = implode(', ', array_map(function($value) {
                return is_string($value) ? "'" . $value . "'" : ($value === null ? 'NULL' : $value);
            }, $argument));
        }

        return $argument;
    }

    private function processArrayKeys($argument): string
    {
        if (is_array($argument)) {
            $argument = implode(', ', array_map(function($val) {
                return is_string($val) ? "`$val`" : $val;
            }, $argument));
        } else {
            $argument = is_string($argument) ? "`$argument`" : $argument;
        }

        return $argument;
    }

    /**
     * @throws Exception
     */
    private function processDefaultArgument($argument): string
    {
        if ($argument === null) {
            $argument = 'NULL';
        } elseif (is_string($argument)) {
            $argument = "'" . $argument . "'";
        } elseif (is_bool($argument)) {
            $argument = $argument ? '1' : '0';
        }else {
            throw new Exception("The argument is wrong");
        }

        return $argument;
    }

    private function finalizeQuery(string $lastQueryPart): string
    {
        return $lastQueryPart === '}' ? '}' : '';
    }

    private function getQueryPart($specifier, $queryPart): string
    {
        return trim($specifier) === '?' ? $queryPart : substr($queryPart, 1);
    }

    private function removeSkippedParts(string $builtQuery, array $args): string
    {
        $startIndex = $this->multidimensionalArraySearch($this->skip(), $args);

        if ($startIndex !== false) {
            $skipArray = array_slice($args, $startIndex);
        }

        preg_match_all('/\{(.*?)\}/', $builtQuery, $matches);

        if (!empty($matches[1])) {
            $partsWithinCurlyBraces = $matches[1];
            foreach ($partsWithinCurlyBraces as $key => $part) {
                if (isset($skipArray[$key]) && $skipArray[$key] === $this->skip()) {
                    $builtQuery = str_replace($part, '', $builtQuery);
                }
            }
        }
        return trim(str_replace(['{', '}'], '', $builtQuery));
    }

    private function multidimensionalArraySearch($needle, $haystack) {
        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($haystack));
        foreach ($iterator as $key => $value) {
            if ($value === $needle) {
                return $key;
            }
        }
        return false;
    }

    public function skip(): string
    {
        return '__SKIP__';
    }
}

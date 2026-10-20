<?php

/** Client directives are not SQL. PDO sends complete statements to the server. */
function mariaDbSqlForPdo(string $sql): string
{
    // The installer uses only $$ for compound trigger bodies, then restores ;.
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^DELIMITER\s+(.+)$/', trim($line), $match)
            && !in_array(trim($match[1]), ['$$', ';'], true)) {
            throw new InvalidArgumentException('Delimitador SQL no admitido en el fixture.');
        }
    }
    $sql = preg_replace('/^DELIMITER[ \t]+(?:\$\$|;)[ \t]*\r?$/m', '', $sql);
    return str_replace('END$$', 'END;', $sql);
}

<?php

use Tests\Support\MysqlStatementSplitter;

/*
 * The PDO fallback in Tests\TestCase loads database/schema/mysql-schema.sql
 * statement by statement. Since the 31 Oct 2026 refresh that file is a full
 * mysqldump (data rows and triggers), so splitting must follow the mysql
 * client's rules rather than "a line ending in ;".
 */

function splitSql(string $sql): array
{
    return iterator_to_array(MysqlStatementSplitter::split($sql), false);
}

it('splits plain statements and keeps executable comments', function (): void {
    $sql = <<<'SQL'
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
-- a line comment
DROP TABLE IF EXISTS `users`;
# a hash comment
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT, -- trailing comment
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;
SQL;

    expect(splitSql($sql))->toBe([
        "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */",
        'DROP TABLE IF EXISTS `users`',
        "CREATE TABLE `users` (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT, \n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB",
    ]);
});

it('does not split inside quoted data', function (): void {
    $sql = "INSERT INTO `notes` (`id`, `body`) VALUES (1,'ends with;\\nand -- not a comment');\n"
        ."INSERT INTO `notes` (`id`, `body`) VALUES (2,'it''s; quoted \\' too');\n"
        .'INSERT INTO `odd;name` (`id`) VALUES (3);';

    expect(splitSql($sql))->toBe([
        "INSERT INTO `notes` (`id`, `body`) VALUES (1,'ends with;\\nand -- not a comment')",
        "INSERT INTO `notes` (`id`, `body`) VALUES (2,'it''s; quoted \\' too')",
        'INSERT INTO `odd;name` (`id`) VALUES (3)',
    ]);
});

it('follows DELIMITER blocks the way mysqldump writes triggers', function (): void {
    $sql = <<<'SQL'
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `guard` BEFORE DELETE ON `ledger` FOR EACH ROW BEGIN
  IF OLD.id > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append only; no deletes';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
SQL;

    $statements = splitSql($sql);

    expect($statements)->toHaveCount(3)
        ->and($statements[0])->toBe('/*!50003 SET @saved_sql_mode       = @@sql_mode */')
        ->and($statements[1])->toStartWith('/*!50003 CREATE*/ /*!50003 TRIGGER `guard`')
        ->toContain("SET MESSAGE_TEXT = 'append only; no deletes';\n  END IF;\nEND */")
        ->and($statements[2])->toBe('/*!50003 SET sql_mode              = @saved_sql_mode */');
});

it('drops plain block comments and keeps a statement without a final delimiter', function (): void {
    expect(splitSql("/* header */\nSELECT 1 /* inline */ + 2;\nSELECT 'tail'"))->toBe([
        'SELECT 1   + 2',
        "SELECT 'tail'",
    ]);
});

it('reads CRLF line ends as LF, as the Windows mysql client does', function (): void {
    $sql = "DELIMITER ;;\r\nCREATE TRIGGER `t` BEFORE DELETE ON `x` FOR EACH ROW BEGIN\r\n  SET @a = 1;\r\nEND;;\r\nDELIMITER ;\r\nINSERT INTO `x` VALUES (1,'a\\r\\nb');\r\n";

    expect(splitSql($sql))->toBe([
        "CREATE TRIGGER `t` BEFORE DELETE ON `x` FOR EACH ROW BEGIN\n  SET @a = 1;\nEND",
        "INSERT INTO `x` VALUES (1,'a\\r\\nb')",
    ]);
});

it('treats a double dash without a following space as SQL', function (): void {
    expect(splitSql('SELECT 5--1;'))->toBe(['SELECT 5--1']);
});

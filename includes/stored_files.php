<?php
/*
|--------------------------------------------------------------------------
| FILES THAT SURVIVE A DEPLOY
|--------------------------------------------------------------------------
|
| Render's free instances have no persistent disk. Anything written to the
| filesystem at runtime lasts until the next deploy or the next time the
| container spins down -- which on a free instance is after fifteen minutes
| of quiet -- and then it is gone, while the database row naming it stays
| behind pointing at nothing.
|
| An approved business saw the result:
|
|     That file is no longer on record.
|
| The row was intact and the path was right. realpath() returned false
| because the PDF had been wiped out from under it. None of this happens on
| XAMPP, where the disk is a disk, which is why it shipped.
|
| So the bytes go where the rest of the record already goes: the database,
| which is the only durable thing this deployment has.
|
| WHY IT IS KEYED BY THE PATH
|
| company_contracts.issued_template, company_contracts.signed_contract and
| platform_settings.contract_template all hold a string like
| "uploads/company_contracts/x.pdf". Those strings stay exactly as they are
| and become opaque keys. Nothing that reads or writes a contract has to
| learn a new shape, no column changes type, and a host with a real disk can
| go on serving the file it already has -- see sendContractFile(), which
| asks the store first and falls back to the filesystem.
|
| WHY THE TABLE CREATES ITSELF
|
| The deployment has no shell, so there is nobody to run a migration. The
| same reasoning as register.php making its upload directory on demand: a
| step that depends on somebody remembering is a step that fails the first
| time it matters, silently, on the live site.
*/

/*
| A file is stored in pieces.
|
| Not for the column's sake -- LONGBLOB holds four gigabytes. For the wire.
| A statement larger than the server's max_allowed_packet is refused before
| it is parsed, and that limit is the server's to set, not ours: this
| machine's MariaDB allows 1 MB, Azure's MySQL allows considerably more, and
| the difference between those two has already produced four separate bugs
| in this project. A 5 MB agreement sent whole works in one of those places
| and fails in the other, on a real contract, in production.
|
| 256 KB sits far enough under the smallest limit worth supporting that the
| statement's own overhead cannot push it over.
*/
if (!defined('PLATFORM_FILE_CHUNK_BYTES')) {
    define('PLATFORM_FILE_CHUNK_BYTES', 256 * 1024);
}

if (!function_exists('platformFilesTable')) {

    /*
    | LONGBLOB rather than BLOB: BLOB stops at 64 KB and would truncate a
    | real PDF without saying so. The key is the path AND the piece's
    | position, so storing twice at one path replaces piece for piece and
    | reading orders them back.
    */
    function platformFilesTable(mysqli $conn): bool
    {
        static $ready = false;

        if ($ready) {
            return true;
        }

        $made = (bool) $conn->query("
            CREATE TABLE IF NOT EXISTS platform_files (
                file_path   VARCHAR(255) NOT NULL,
                chunk_index INT(11)      NOT NULL DEFAULT 0,
                mime_type   VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
                file_name   VARCHAR(190) NOT NULL DEFAULT '',
                file_bytes  LONGBLOB     NOT NULL,
                created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (file_path, chunk_index)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        /*
        | CREATE TABLE IF NOT EXISTS does nothing to a table that already
        | exists in an older shape -- it reports success and leaves the
        | missing column missing, so the next query fails on a table the code
        | believes it just made. This version of the file stores a document
        | in pieces where the first stored it whole, and any database written
        | by that first version is one column short.
        |
        | Asked of information_schema rather than with ADD COLUMN IF NOT
        | EXISTS, which MariaDB accepts and MySQL 8.4 rejects outright. The
        | two servers' differences have cost this project four bugs already;
        | this one would have been the fifth, and would have appeared only on
        | Azure.
        */
        if ($made) {

            $column = $conn->query("
                SELECT COUNT(*) AS n
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'platform_files'
                  AND COLUMN_NAME = 'chunk_index'
            ");

            $has = $column ? (int) $column->fetch_assoc()['n'] : 1;

            if ($has === 0) {
                $conn->query("
                    ALTER TABLE platform_files
                        ADD COLUMN chunk_index INT(11) NOT NULL DEFAULT 0 AFTER file_path,
                        DROP PRIMARY KEY,
                        ADD PRIMARY KEY (file_path, chunk_index)
                ");
            }
        }

        $ready = $made;

        return $ready;
    }
}

if (!function_exists('platformFileStore')) {

    /**
     * Keeps a file's bytes against its path, replacing whatever was there.
     */
    function platformFileStore(
        mysqli $conn,
        string $path,
        string $bytes,
        string $mime = 'application/octet-stream',
        string $name = ''
    ): bool {

        if (trim($path) === '' || !platformFilesTable($conn)) {
            return false;
        }

        $name = mb_substr($name, 0, 190);

        /*
        | str_split('') is one empty string on PHP 8.2 and an empty array on
        | 8.3, so an empty file would write no rows at all on a newer runtime
        | and read back as "never stored". An empty PDF is not a real case; a
        | store that behaves differently per PHP version is.
        */
        $chunks = $bytes === '' ? [''] : str_split($bytes, PLATFORM_FILE_CHUNK_BYTES);

        /*
        | All the pieces or none of them. A write interrupted halfway leaves
        | a file that reads back truncated -- which is worse than one that
        | fails, because a truncated PDF is a file somebody will try to open.
        */
        $conn->begin_transaction();

        try {
            /* The old pieces go first: a shorter file would otherwise keep
               the tail of the longer one it replaced. */
            platformFileForget($conn, $path);

            $stmt = $conn->prepare("
                INSERT INTO platform_files
                    (file_path, chunk_index, mime_type, file_name, file_bytes)
                VALUES (?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new RuntimeException('The file store could not be prepared.');
            }

            foreach ($chunks as $index => $chunk) {

                $stmt->bind_param("sisss", $path, $index, $mime, $name, $chunk);

                if (!$stmt->execute()) {
                    throw new RuntimeException('A piece of the file could not be stored.');
                }
            }

            $stmt->close();
            $conn->commit();

            return true;

        } catch (Throwable $error) {

            $conn->rollback();
            error_log('platformFileStore failed for ' . $path . ': ' . $error->getMessage());

            return false;
        }
    }
}

if (!function_exists('platformFileRead')) {

    /**
     * The stored file, or null where this path was never stored.
     *
     * @return array{bytes:string,mime:string,name:string}|null
     */
    function platformFileRead(mysqli $conn, ?string $path): ?array
    {
        $path = trim((string) $path);

        if ($path === '' || !platformFilesTable($conn)) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT mime_type, file_name, file_bytes
            FROM platform_files
            WHERE file_path = ?
            ORDER BY chunk_index
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("s", $path);
        $stmt->execute();

        $rows = $stmt->get_result();

        $bytes = '';
        $mime = '';
        $name = '';
        $found = false;

        while ($row = $rows->fetch_assoc()) {
            $bytes .= (string) $row['file_bytes'];
            $mime = (string) $row['mime_type'];
            $name = (string) $row['file_name'];
            $found = true;
        }

        $stmt->close();

        if (!$found) {
            return null;
        }

        return ['bytes' => $bytes, 'mime' => $mime, 'name' => $name];
    }
}

if (!function_exists('platformFileForget')) {

    function platformFileForget(mysqli $conn, ?string $path): bool
    {
        $path = trim((string) $path);

        if ($path === '' || !platformFilesTable($conn)) {
            return false;
        }

        $stmt = $conn->prepare("DELETE FROM platform_files WHERE file_path = ?");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("s", $path);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('uploadErrorMessage')) {

    /*
    | What PHP actually refused.
    |
    | Seven codes were reported as "The file could not be uploaded.", and two
    | of them -- no temporary directory, and a failed write -- are about the
    | server rather than the file. On a host where those are the likely
    | causes, a message that cannot name them sends somebody to inspect a PDF
    | that was never the problem.
    */
    function uploadErrorMessage(int $code): string
    {
        switch ($code) {

            case UPLOAD_ERR_INI_SIZE:
                return 'The file is larger than the server accepts.';

            case UPLOAD_ERR_FORM_SIZE:
                return 'The file is larger than this form accepts.';

            case UPLOAD_ERR_PARTIAL:
                return 'The file was only partly uploaded. Please try again.';

            case UPLOAD_ERR_NO_FILE:
                return 'No file was chosen.';

            case UPLOAD_ERR_NO_TMP_DIR:
                return 'The server has no temporary folder to receive uploads. '
                     . 'This is a fault on our side, not with your file.';

            case UPLOAD_ERR_CANT_WRITE:
                return 'The server could not write the file to disk. '
                     . 'This is a fault on our side, not with your file.';

            case UPLOAD_ERR_EXTENSION:
                return 'The upload was stopped by the server. '
                     . 'This is a fault on our side, not with your file.';
        }

        return 'The file could not be uploaded (error ' . $code . ').';
    }
}

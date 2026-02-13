<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Search;

/**
 * Manages the FTS5 search index stored in a SQLite database.
 *
 * Handles schema creation, section indexing, BM25-ranked querying,
 * and index statistics. Uses raw PDO (not Yii2 DB component) since
 * the search index is a separate SQLite file.
 */
class SearchIndexManager
{
    /**
     * @var \PDO Database connection
     */
    private $pdo;

    /**
     * @var string Path to the SQLite database file
     */
    private $dbPath;

    /**
     * @param string $dbPath Path to the SQLite database file (use ':memory:' for tests)
     */
    public function __construct(string $dbPath)
    {
        $this->dbPath = $dbPath;

        // Ensure directory exists
        if ($dbPath !== ':memory:') {
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $this->pdo = new \PDO('sqlite:' . $dbPath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        // Enable WAL mode for better concurrent read performance
        if ($dbPath !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode=WAL');
        }
    }

    /**
     * Create the FTS5 schema (idempotent).
     */
    public function createSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS search_sections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL,
                category TEXT NOT NULL,
                file_path TEXT NOT NULL,
                file_title TEXT NOT NULL,
                section_title TEXT NOT NULL,
                body TEXT NOT NULL,
                indexed_at TEXT NOT NULL
            )
        ');

        // Check if FTS table already exists
        /** @var \PDOStatement $stmt */
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='search_fts'"
        );
        if ($stmt->fetch() === false) {
            $this->pdo->exec("
                CREATE VIRTUAL TABLE search_fts USING fts5(
                    file_title, section_title, body,
                    content='search_sections', content_rowid='id',
                    tokenize='porter unicode61'
                )
            ");
        }

        // Auto-sync triggers
        $this->pdo->exec("
            CREATE TRIGGER IF NOT EXISTS search_sections_ai AFTER INSERT ON search_sections BEGIN
                INSERT INTO search_fts(rowid, file_title, section_title, body)
                VALUES (new.id, new.file_title, new.section_title, new.body);
            END
        ");

        $this->pdo->exec("
            CREATE TRIGGER IF NOT EXISTS search_sections_ad AFTER DELETE ON search_sections BEGIN
                INSERT INTO search_fts(search_fts, rowid, file_title, section_title, body)
                VALUES ('delete', old.id, old.file_title, old.section_title, old.body);
            END
        ");

        // Metadata table
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS search_index_meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ');
    }

    /**
     * Clear all indexed content (preserves schema).
     */
    public function clearIndex(): void
    {
        $this->pdo->exec('DELETE FROM search_sections');
        $this->setMeta('last_rebuild', '');
        $this->setMeta('section_count', '0');
    }

    /**
     * Index sections from a parsed markdown file.
     *
     * @param string $source Content source ('bundled' or 'yii2_guide')
     * @param string $category Category name (e.g., 'database', 'cache')
     * @param string $filePath Original file path
     * @param string $fileTitle Document title from H1
     * @param array $sections Parsed sections from MarkdownSectionParser
     * @return int Number of sections indexed
     */
    public function indexSections(
        string $source,
        string $category,
        string $filePath,
        string $fileTitle,
        array $sections
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT INTO search_sections (source, category, file_path, file_title, section_title, body, indexed_at)
            VALUES (:source, :category, :file_path, :file_title, :section_title, :body, :indexed_at)
        ');

        $now = date('Y-m-d H:i:s');
        $count = 0;

        foreach ($sections as $section) {
            if (empty($section['body'])) {
                continue;
            }

            $stmt->execute([
                ':source' => $source,
                ':category' => $category,
                ':file_path' => $filePath,
                ':file_title' => $fileTitle,
                ':section_title' => $section['section_title'],
                ':body' => $section['body'],
                ':indexed_at' => $now,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Search the index using FTS5 with BM25 ranking.
     *
     * @param string $query Search query (supports FTS5 syntax: phrases, AND/OR/NOT, prefix*)
     * @param string $category Optional category filter ('all' for no filter)
     * @param int $limit Maximum results (1-10)
     * @return array Ranked search results
     */
    public function search(string $query, string $category = 'all', int $limit = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // Sanitize query for FTS5: escape double quotes, wrap bare terms
        $ftsQuery = $this->buildFtsQuery($query);

        $sql = '
            SELECT
                s.id,
                s.source,
                s.category,
                s.file_path,
                s.file_title,
                s.section_title,
                s.body,
                rank
            FROM search_fts f
            JOIN search_sections s ON f.rowid = s.id
            WHERE search_fts MATCH :query
        ';

        $params = [':query' => $ftsQuery];

        if ($category !== 'all') {
            $sql .= ' AND s.category = :category';
            $params[':category'] = $category;
        }

        $sql .= ' ORDER BY rank LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Build a safe FTS5 query string from user input.
     *
     * Handles common cases:
     * - Bare words: "migration database" -> "migration" OR "database"
     * - Quoted phrases passed through: "active record"
     * - Prefix wildcards: migrat* -> migrat*
     * - Boolean operators preserved: migration AND database
     *
     * @param string $query Raw user query
     * @return string FTS5-safe query
     */
    private function buildFtsQuery(string $query): string
    {
        // If query already contains FTS5 operators or quotes, pass through
        if (preg_match('/["\*]|(?:^|\s)(?:AND|OR|NOT)(?:\s|$)/', $query)) {
            return $query;
        }

        // Split into words and join with OR for broader matching
        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return $query;
        }

        // For single word, use as-is
        if (count($words) === 1) {
            return $words[0];
        }

        // For multiple words, try phrase match first (boosted) OR individual terms
        $escaped = str_replace('"', '', $query);
        $orTerms = implode(' OR ', $words);

        return '"' . $escaped . '" OR ' . $orTerms;
    }

    /**
     * Get index statistics.
     *
     * @return array Index stats including section count, sources, categories
     */
    public function getStats(): array
    {
        /** @var \PDOStatement $totalStmt */
        $totalStmt = $this->pdo->query('SELECT COUNT(*) AS cnt FROM search_sections');
        $total = (int) $totalStmt->fetchColumn();

        /** @var \PDOStatement $sourceStmt */
        $sourceStmt = $this->pdo->query(
            'SELECT source, COUNT(*) AS cnt FROM search_sections GROUP BY source ORDER BY source'
        );
        $sources = [];
        while ($row = $sourceStmt->fetch()) {
            $sources[$row['source']] = (int) $row['cnt'];
        }

        /** @var \PDOStatement $catStmt */
        $catStmt = $this->pdo->query(
            'SELECT category, COUNT(*) AS cnt FROM search_sections GROUP BY category ORDER BY category'
        );
        $categories = [];
        while ($row = $catStmt->fetch()) {
            $categories[$row['category']] = (int) $row['cnt'];
        }

        $lastRebuild = $this->getMeta('last_rebuild');

        return [
            'total_sections' => $total,
            'sources' => $sources,
            'categories' => $categories,
            'last_rebuild' => $lastRebuild ?: 'never',
            'db_path' => $this->dbPath,
        ];
    }

    /**
     * Get all distinct categories in the index.
     *
     * @return array List of category names
     */
    public function getCategories(): array
    {
        /** @var \PDOStatement $stmt */
        $stmt = $this->pdo->query('SELECT DISTINCT category FROM search_sections ORDER BY category');
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Set a metadata value.
     *
     * @param string $key Metadata key
     * @param string $value Metadata value
     */
    public function setMeta(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO search_index_meta (key, value) VALUES (:key, :value)
            ON CONFLICT(key) DO UPDATE SET value = :value
        ');
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    /**
     * Get a metadata value.
     *
     * @param string $key Metadata key
     * @return string|null Value or null if not found
     */
    public function getMeta(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM search_index_meta WHERE key = :key');
        $stmt->execute([':key' => $key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (string) $result : null;
    }

    /**
     * Check if the FTS5 extension is available.
     *
     * @return bool
     */
    public static function isFts5Available(): bool
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec("CREATE VIRTUAL TABLE _fts5_test USING fts5(content)");
            $pdo->exec("DROP TABLE _fts5_test");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the database path.
     *
     * @return string
     */
    public function getDbPath(): string
    {
        return $this->dbPath;
    }
}

<?php
// noXXXep - The Insideous Bug Tracker
// https://github.com/ProgerXP/noXXXep | Public domain/Unlicense

class noXXXep {
  const NORMAL  = 'normal';

  const WHITESPACE = "\x0\x1\x2\x3\x4\x5\x6\x7\x8\x9\xa\xb\xc\xd\xe\xf\x10\x11".
                     "\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f".
                     "\x20";

  const ID = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz';

  // Relative to CWD.
  protected $cacheFile = 'noXXXep-cache.php';
  protected $tempFile;    // always in the directory of $cacheFile
  protected $mediaPath = 'noXXXep.';    // <this>.css|js
  protected $cache;
  protected $title = 'The Insideous Bug Tracker';
  // Notepad 2e | https://github.com/ProgerXP/Notepad2e
  // For best experience, once a window opens enable Settings > Reuse Window and
  // File > Save On Lose Focus in it.
  protected $launchSelection  = 'start "" Notepad /gs %d:%d %s';
  protected $launchLine       = 'start "" Notepad /g %d %s';
  protected $launchFind       = 'start "" Notepad /m %s %s';
  protected $launchFile       = 'start "" Notepad %s';
  // Notepad++ | https://notepad-plus-plus.org
  //protected $launchSelection  = 'start "" Notepad++ -p%d %3$s';
  //protected $launchLine       = 'start "" Notepad++ -n%d %s';
  //protected $launchFind       = '';   // not supported
  //protected $launchFile       = 'start "" Notepad++ %s';

  protected $readOnly = false;
  // Avoids race condition where a file was moved while the directory was
  // scanned (e.g. if a folder was moved from A to B after opendir(), readdir()
  // will work but actual file name will be not A/file but B/file). Enabling
  // guarantees that the intended file is changed (unless it's a hard link) but
  // also causes all symlinks to be skipped.
  protected $skipByRealpath = true;
  // Folders with more than this number of folders are skipped (but this many
  // "certain" subfolders are still descended into in case of empty cache).
  // Else, folders with "certain" autoSkip-N files (N = number of subfolders)
  // that did not satisfy matchFile() are skipped. "Certain" means order in
  // which readdir() returns entries (i.e. "certain" = "arbitrary").
  protected $autoSkip = 100;
  protected $rootPath = '.';
  //= callable ($full, $relative to $rootPath): bool
  protected $directoryMatcher;
  protected $directoryRE = '//';
  // 0 (process only files, don't descend into subfolders), 1 (process immediate
  // subfolders of root), etc.
  protected $directoryDepth = 10;
  protected $fileMatcher;
  protected $fileRE = '/\.(php|js|css|html)$/ui';
  protected $fileSize = 1024 * 1024;
  protected $trailerCount = 5;
  // Clear cache after changing this because it changes meaning of "XXX" without
  // explicit priority.
  protected $defaultPriority = self::NORMAL;
  // Strings displayed to the user instead of values used in "XXX" statements.
  //= ['value' => 'Title', ...]
  protected $tagNames = [];
  protected $groupNames = [];
  //= callable (object $file, object $task): string
  protected $fileURL;

  // Order affects <select>. After changing on run-time and not from within
  // local config.php, unset $xxxRE.
  protected $priorities = [
    '++'  => 'highest',
    '+'   => 'high',
    '='   => self::NORMAL,
    '-'   => 'low',
    '--'  => 'lowest',
  ];

  protected $writeCacheError;
  protected $xxxRE;
  protected $filters;
  protected $matchingGroups = [];    // id => true

  protected $time_readCache;
  protected $time_scan;
  protected $time_changedFiles;
  protected $time_prune;
  protected $time_refreshIndex;
  protected $time_writeCache;
  protected $time_filterTasks;
  protected $time_formatTasks;

  // Returned string is guaranteed to be a valid JSON string only if $str is a
  // valid "easy JSON" string.
  //
  // Upon return, $pos is either after \r\n or before EOF.
  static function extractJSON($str, &$pos = 0, $prefix = '') {
    static $scalarRE = '/\\G(true|false|null|-?\\d+(\\.\\d+)?([eE][-+]?\\d+)?)\b/u';

    $slen = strlen($str);
    $plen = strlen($prefix);
    $cprefix = rtrim($prefix);
    $cprefixlen = strlen($cprefix);
    $json = $cx = [];   // $cx: one of { [ "
    $stringEnd = 0;
    $top = null;

    while ($pos < $slen and (
           ($nonBlank = !substr_compare($str, $prefix, $pos, $plen)) or
            ($cprefixlen and !substr_compare($str, $cprefix, $pos, $cprefixlen)
             and strpbrk($str[$pos + $cprefixlen], "\r\n")))) {
      $pos += $nonBlank ? $plen : $cprefixlen;

      while ($pos < $slen) {
        // https://www.json.org
        switch ($ch = $str[$pos++]) {
          case "\r":
            if ("\n" === ($str[$pos] ?? '')) {
              $pos++;
            }
          case "\n":
            if ($top === '"') {
              // "str     => "str\ncont"
              // cont"
              $json[] = '\n';
            }
            break 2;
          case '"':
            if ($top === $ch) {
              // Merge strings: "a" "b""c" => "abc"
              // Cannot combine with non-string tokens: "a" 1.2 foo
              $stringEnd and array_splice($json, $stringEnd - 1, 2);
              array_shift($cx);
              $top = $cx[0];
              $stringEnd = array_push($json, $ch);
            } elseif (!$cx) {
              // Easy JSON is always an {object}. Root "string" = invalid.
              return;
            } else {
              array_unshift($cx, $json[] = $top = $ch);
            }
            break;
          case '{':
          case '[':
            if ($top !== '"') {
              $stringEnd = 0;
              array_unshift($cx, $json[] = $top = $ch);
              $key = true;
              break;
            }
          case '}':
          case ']':
            if ($top !== '"') {
              $stringEnd = 0;
              if (ord(array_shift($cx)) !== ord($ch) - 2) {
                return;   // malformed JSON
              }
              $json[] = $ch;
              if (!$cx) { break 3; }
              $top = $cx[0];
              break;
            }
          case ':':
          case ',':
            if ($top !== '"') {
              $stringEnd = 0;
              $json[] = $ch;
              $key = $ch === ',';
              break;
            }
          case 't':
          case 'f':
          case 'n':
          case '-':
          case '0': case '1': case '2': case '3': case '4':
          case '5': case '6': case '7': case '8': case '9':
            // Scalar-looking tokens become strings in object $key-s:
            // {3.14: "v", null: "v"} => {"3.14": "v", "null": "v"}
            if (($top === '[' or ($top === '{' and !$key)) and
                preg_match($scalarRE, $str, $match, 0, $pos - 1)) {
              $json[] = $match[0];
              $pos += strlen($match[0]) - 1;
              break;
            }
          default:
            if ($top === '"') {
              if ($ch !== '\\') {
                $json[] = $ch;
              } elseif (!strpbrk("\r\n", $str[$pos] ?? "\n")) {
                $len = $str[$pos] === 'u' ? 5 : 1;
                $json[] = $ch.substr($str, $pos, $len);
                $pos += $len;
                // Eat \ before \r, \n or EOF.
              }
            } elseif (ord($ch) <= 0x20) {
              // Skip non-\r\n whitespace.
            } elseif (strspn($ch, static::ID)) {
              // Unwrapped identifier string: [{k: v}, v] => [{"k": "v"}, "v"]
              // Cannot combine with regular strings: [{k k: "v" v}, v "v"]
              $len = strspn($str, static::ID, $pos);
              $json[] = '"'.$ch.substr($str, $pos, $len).'"';
              $pos += $len;
            } else {
              return;   // malformed JSON
            }
        }
      }
    }

    return join($json);

    // {k: [v, {kk: true}, null, false, 123], 456: "a \\\"\u1234\
    // b" "c"
    // "d"}
  }

  function __construct() {
    while (is_writable('.') and file_exists($this->tempFile ?: '.')) {
      $this->tempFile = mt_rand().'.php';
    }
  }

  function rootPath() {
    $path = rtrim(realpath($this->rootPath), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

    if (!is_dir($path)) {
      throw new Exception("\$rootPath must point to an existing directory: $this->rootPath, CWD = ".getcwd());
    }

    return $path;
  }

  protected function readCache() {
    $time = microtime(true);
    if (function_exists('opcache_invalidate')) {
      opcache_invalidate($this->cacheFile);
    }
    try {
      $data = $this->lockFile($this->cacheFile, function () {
        return include $this->cacheFile;
      }, true);
    } catch (Throwable $e) {}
    $this->time_readCache = microtime(true) - $time;
    return is_object($data ?? null) ? $data : new stdClass;
  }

  protected function writeCache(stdClass $data) {
    if ($this->tempFile) {
      try {
        $time = microtime(true);
        $this->lockFile($this->cacheFile, function () use ($data) {
          $date = date(DATE_ATOM);
          $data = "<?php\n// $date\nreturn ".var_export($data, true).';';
          if (version_compare(PHP_VERSION, '7.3.0', '<')) {
            $data = str_replace('stdClass::__set_state(', '((object) ', $data);
          }
          file_put_contents($this->tempFile, $data);
          rename($this->tempFile, $this->cacheFile);
        });
        $this->time_writeCache = microtime(true) - $time;
      } catch (Throwable $e) {
        $this->writeCacheError = $e;
      }
    }
  }

  // $file - relative to $rootPath.
  protected function writeSource($file, $data, $hash, $realpath = null) {
    $file = $this->rootPath().$file;
    return $this->lockFile($file, function ()
        use ($file, $data, $hash, $realpath) {
      if ($realpath and $realpath !== $real = realpath($file)) {
        throw new Exception("File moved since last scan: old path = $file, old realpath = $realpath, new realpath = $real");
      }
      while (true) {
        $temp = preg_replace('/(\.[^.]+)?$/u', '-'.mt_rand().'$1', $file, 1);
        try {
          fclose(fopen($temp, 'x'));
          break;
        } catch (Throwable $e) {}
      }
      rename($file, $temp);
      try {
        $actual = rtrim(base64_encode(hash_file('md5', $temp, true)), '=');
        if ($hash !== $actual) {
          throw new Exception("File changed since last scan: old hash = $hash, current hash = $actual, file = $file");
        }
        file_put_contents("-$temp", $data);
        rename("-$temp", $file);
        unlink($temp);
      } catch (Throwable $e) {
        rename($temp, $file);
        throw $e;
      }
    });
  }

  // $file - relative to CWD.
  protected function lockFile($file, callable $func, $try = false) {
    try {
      $h = fopen($file = "$file.lock", 'w');
    } catch (Throwable $e) {
      if (!$try) { throw $e; }
      return $func();
    }
    if (!flock($h, LOCK_EX)) {
      throw new Exception("Unable to flock($file).");
    }
    try {
      return $func();
    } finally {
      flock($h, LOCK_UN);
      fclose($h);
      unlink($file);
    }
  }

  function refreshIfNeeded() {
    $this->refresh($this->readCache());
  }

  function refresh(stdClass $cache) {
    $ds = DIRECTORY_SEPARATOR;
    $epoch = $cache->epoch = ($cache->epoch ?? 0) + 1;
    $changedFiles = [];

    // matchFile() expects $cacheFile to be canonical. $cacheFile may not exist,
    // in this case it won't turn up in scanned files so don't resolve it.
    if (file_exists($this->cacheFile)) {
      $this->cacheFile = realpath($this->cacheFile);
    }

    $scan = function ($path, $root)
        use (&$scan, &$cache, &$epoch, &$changedFiles, $ds) {
      try {
        $dir = opendir($root.$path);
      } catch (Throwable $e) {
        return;
      }

      $entries = 0;

      while (false !== $file = readdir($dir)) {
        if ($file !== '.' and $file !== '..') {
          $file = $path.$file;
          $full = $root.$file;
          clearstatcache(false, $full);

          if (++$entries > $this->autoSkip) {
            $cache->autoSkip[$path] = true;
            break;
          }

          if (is_dir($full)) {
            if ($this->matchDirectory($root, $file) and
                empty($cache->autoSkip[$file.$ds])) {
              $scan($file.$ds, $root);
            }
          } elseif (is_file($full)) {
            try {     // filemtime() and insides of matchFile() may error
              if ($this->matchFile($root, $file)) {
                $entries = PHP_INT_MIN;
                $time = filemtime($full);
                if ($time === (($p = $cache->paths[$file] ?? null)->time ?? null)) {
                  $cache->files[$p->hash]->paths[$file] = $epoch;
                  $p->epoch = $epoch;
                } elseif (!$this->skipByRealpath or $full === $real = realpath($full)) {
                  $changedFiles[] = [$file, $time, $real ?? realpath($full)];
                }
              }
            } catch (Throwable $e) {}
          }
        }
      }

      closedir($dir);
    };

    $time = microtime(true);
    $scan('', $this->rootPath());
    $this->time_scan = microtime(true) - $time;

    // $unique is used for comparing cache instances (updates). If $cacheFile is
    // not used, each new request generates new hash; using 0 for $unique means
    // each new cache is seen as the same instance. Using PRNG allows comparing
    // instances even in absence of a persistent storage.
    isset($cache->unique) or $cache->unique = mt_rand();
    isset($cache->files) or $cache->files = [];
    isset($cache->paths) or $cache->paths = [];
    $timeCF = microtime(true);
    $changed = false;

    foreach ($changedFiles as [$path, $time, $realpath]) {
      $content = $this->lockFile($realpath, function () use ($realpath) {
        try {
          return file_get_contents($realpath);
        } catch (Throwable $e) {}
      }, true);

      if ($content !== null) {
        $hash = rtrim(base64_encode(hash('md5', $content, true)), '=');

        if (empty($cache->files[$hash]->paths[$path])) {
          if (empty($cache->files[$hash])) {
            $cache->files[$hash] = (object) [
              'unique' => ++$cache->unique,
              'tasks' => $this->extractXXX($content),
              'paths' => [],
            ];
          }

          $changed = $changed || $cache->files[$hash]->tasks;
        }

        $cache->files[$hash]->paths[$path] = $epoch;
        $cache->paths[$path] =
          (object) compact('hash', 'time', 'realpath', 'epoch');
      }
    }

    $changedFiles and $this->time_changedFiles = microtime(true) - $timeCF;

    if ($epoch > 1) {
      $time = microtime(true);

      foreach ($cache->files as $hash => $file) {
        foreach ($file->paths as $path => $pathEpoch) {
          if ($pathEpoch !== $epoch) {
            unset($file->paths[$path]);
            $changed = true;
          }
        }

        if (!$file->paths) {
          // Keeping old parsed files around in case they reappear.
          if (!isset($recycle) and
              $recycle = ($cache->recycled ?? 0) + 3 * 24 * 3600 < time()) {
            $cache->recycled = time();
          }
          if ($recycle) {
            unset($cache->files[$hash]);
          }
        }
      }

      if ($changed) {
        foreach ($cache->paths as $path => $file) {
          if ($file->epoch !== $epoch) { unset($cache->paths[$path]); }
        }
      }

      $this->time_prune = microtime(true) - $time;
    }

    // Add index fields if current cache is new but empty (no files matched).
    if ($changed or $epoch === 1) {
      $this->refreshIndex($cache);
    }

    // Only update $time when something was $changed. It isn't necessarily
    // updated every time the cache is written. $time is used in
    // If-Modified-Since and if $changedFiles was non-empty while $changed was
    // unset, new cache has no changes in 'tasks' and thus retains the old
    // $time.
    if ($changed or !isset($cache->time)) {
      $cache->time = time();
    }

    // If nothing was $changed but there were $changedFiles, write the file
    // anyway to update file timestamps and save subsequent requests from $scan.
    if ($changed or $changedFiles or $epoch === 1) {
      $this->writeCache($cache);
    }

    $this->cache = $cache;
  }

  protected function refreshIndex(stdClass $cache) {
    $time = microtime(true);
    $cache->priorities = $cache->ids = $cache->tags = $groups = [];
    $cache->view = (object) ['files' => [], 'ids' => []];

    foreach ($cache->files as $hash => $file) {
      if ($file->paths) {
        $file->tasks and $cache->view->files[$hash] = key($file->paths);

        foreach ($file->tasks as $i => $task) {
          $locator = [$hash, $i, $file->unique];
          $cache->priorities[$task->priority][] = $locator;

          if ($task->id !== '') {
            $cache->ids[$task->id] = $locator;

            if (strspn($task->id, static::ID) === strlen($task->id) and
                strlen($task->id) > 1) {
              $cache->view->ids[$task->id] = $locator;
            }
          }

          foreach ($task->tags as $tag) {
            $cache->tags[$tag][] = $locator;
          }

          foreach ($task->groups ?: [''] as $id) {
            $groups[$id][] = $locator;
          }

          if (($task->blockGroup[0] ?? null) === $i) {
            // It's enough to change $blockGroup of just one task out of
            // $blockGroup since they're &refs.
            foreach ($task->blockGroup as &$ref) {
              $locator[1] = $ref;
              $ref = $locator;
            }
          }
        }
      }
    }

    $orphans = array_diff_key($groups, $cache->ids);
    $cache->orphans = array_intersect_key($groups, $orphans);
    $cache->groups = array_diff_key($groups, $orphans);
    $cache->view->idsRE = join('|', array_keys($cache->view->ids));

    $this->time_refreshIndex = microtime(true) - $time;
  }

  // $root.$path makes for canonical path.
  protected function matchDirectory($root, $path) {
    $full = $root.$path;
    return substr_count($path, DIRECTORY_SEPARATOR) < $this->directoryDepth and
           preg_match($this->directoryRE, $path) and
           (!$this->directoryMatcher or
            call_user_func($this->directoryMatcher, $full, $path));
  }

  // $root.$path makes for canonical path.
  // $cacheFile must be canonical.
  protected function matchFile($root, $path) {
    $full = $root.$path;
    return preg_match($this->fileRE, $path) and $full !== __FILE__ and
           $full !== $this->cacheFile and
           $size = filesize($full) and $size < $this->fileSize and
           (!$this->fileMatcher or
            call_user_func($this->fileMatcher, $full, $path));
  }

  // \b 'XXX' \b [prio [tags] [ ':' [groups] [' ' [id] [' ' [opt]]] (: | '...' [:]) ]]
  // prio = --|-|=|+|++
  // tags = groups = tag [,tag...]
  // opt = key[=[value]] [' ' [k...]]
  //
  // Multiple 'XXX' may coexist on a single line to share the same comment
  // block. '...' interprets remainder of the line as a comment block, and
  // subsequent line(s) as an "easy JSON" (no 'XXX' recognized inside of
  // either).
  function extractXXX($str) {
    if (!$this->xxxRE) {
      $quote = function ($p) { return preg_quote($p, '~'); };
      $prio = array_keys($this->priorities);
      // In order for '--' to match, it must come before '-'.
      usort($prio, function ($a, $b) { return strlen($b) - strlen($a); });
      $prio = join('|', array_map($quote, $prio));

      $this->xxxRE = "
        ~
          \\b XXX \b
          (?:
            ($prio)                   # 1 prio
            (?:
              ([\\w,]*)               # 2 tags
              (?:
                :
                ([\\w,]*)             # 3 groups
                (?:
                  [ ]
                  (\\w*)              # 4 id
                  (?:
                    [ ]
                    (.*?)             # 5 opt
                  )?
                )?
                (?:
                  :
                | (\\.{3}) :?         # 6 ...
                )
              )?
            )
          )?
          ()
        ~xu";
    }

    static $optRE = '/(\\w+)(=(\\S*))?()/u';

    $substrE = function ($start, $end) use (&$str) {
      return substr($str, $start, $end - $start);
    };

    $nextLine = function ($pos, &$llen) use (&$str) {
      $pos += strcspn($str, "\r\n", $pos);
      $llen = $pos >= strlen($str) ? 0
        : 1 + ($str[$pos] === "\r" and ($str[$pos + 1] ?? '') === "\n");
      return $pos;
    };

    // Keeps original order of items while removing duplicates.
    $splitList = function ($s) {
      $res = [];

      foreach (explode(',', $s) as $item) {
        if ($item !== '' and !array_key_exists($item, $res)) {
          $res[$item] = true;
        }
      }

      return array_keys($res);
    };

    $tasks = [];
    $pos = 0;

    while (preg_match($this->xxxRE, $str, $match, PREG_OFFSET_CAPTURE, $pos)) {
      $task = new stdClass;
      $task->startOffset = $match[0][1];
      for ($pos = $task->startOffset; $pos-- and !strpbrk("\r\n", $str[$pos]); ) ;
      $task->prefix = $substrE($pos + 1, $task->startOffset);
      $pos = $task->endOffset = $task->startOffset + strlen($match[0][0]);
      $match = array_column($match, 0);
      list(, , , , $task->id, , $task->wrapped) = $task->match = $match;
      $task->priority = $this->priorities[$match[1]] ?? $this->defaultPriority;
      $task->tags   = $splitList($match[2]);
      $task->groups = $splitList($match[3]);
      $task->options = [];

      if (preg_match_all($optRE, $match[5], $matches)) {
        foreach ($matches[1] as $i => $key) {
          $task->options[$key] = $matches[2][$i] ? $matches[3][$i] : true;
        }
      }

      $task->inline = (
        // Force block on the same line as the previously ended block's JSON to
        // be inline. It doesn't matter to the code if it's inline or not, will
        // work either way but for a user a dedicated block whose prefix is the
        // tail of the previous block's JSON is surely confusing:
        // // XXX=dedicated:  ...:
        // // {js: on}XXX           - forced inline
        // Else something like this would be possible:
        // // XXX=dedicated:  ...:
        // // {js: on
        // // }XXX=:  ...:
        // // }{actual: json}  - "//}" is $prefix
        (($prevJSON ?? 0) >
          // Beginning of line.
          $task->startOffset - strlen($task->prefix)) or
        // // XXX Comment...        - dedicated
        // // Comment (XXX)...      - inline
        // public $foo;   // XXX    - inline
        // // XXX Comment (XXX)...  - dedicated and inline
        preg_match('/\\pL/u', $task->prefix)
      );

      $task->inline and $task->prefix = null;

      if ($task->wrapped and !$task->inline) {
        $pos = $task->jsonStartOffset = $nextLine($pos, $llen) + $llen;

        try {
          $json = static::extractJSON($str, $pos, $task->prefix);
        } catch (Throwable $e) {
          continue;
        }

        if (is_object($json = json_decode($json))) {
          $task->jsonRaw = $substrE($task->jsonStartOffset, $pos);
          $task->jsonKeys = array_keys((array) $json);
          $task->jsonEndOffset = $prevJSON = $pos;
          foreach ($json as $k => $v) { $task->options[$k] = $v; }
        } else {
          unset($task->jsonStartOffset);
        }
      }

      $tasks[] = $task;
    }

    $line = $pos = $prev = 0;
    $props = ['start', 'end', 'jsonStart', 'jsonEnd'];

    foreach ($tasks as $task) {
      foreach ($props as $pf) {   // startOffset startLine startColumn
        if (isset($task->{$pf.'Offset'})) {
          while ($pos < strlen($str) and $pos <= $task->{$pf.'Offset'}) {
            $pos = $nextLine($prev = $pos, $llen) + $llen;
            $line++;
          }
          $task->{$pf.'Line'} = $line - 1;
          $task->{$pf.'LineStart'} = $prev;
          $task->{$pf.'LineEnd'} = $pos - $llen;
          $task->{$pf.'Column'} = $task->{$pf.'Offset'} - $prev;  // in bytes
        }
      }

      $start = $task->startLineStart + strlen($task->prefix ?? '');
      $task->commentOffsets[] = [$start, $task->endLineEnd];
      $task->commentLines[] = $task->endLine;
      $task->comment[] = $substrE($start, $task->endLineEnd);
    }

    // Add comments and trailers to non-$inline, stopping early at the beginning
    // of the next non-$inline block.
    //
    // Post-process comments and trailers:
    // - replace "XXX" declarations with links
    // - remove first in $comments of non-$inline if it consists solely of that
    //   $task's $prefix + "XXX" declaration + whitespace
    for ($start = 0, $count = count($tasks); $start < $count; ) {
      if (!$tasks[$start]->inline) {
        $task = $tasks[$start];
        $plen = strlen($task->prefix);
        $cprefix = rtrim($task->prefix);
        $cprefixlen = strlen($cprefix);
        $trailerCount = $task->options['t'] ?? $this->trailerCount;

        $testPrefix = function ($str, &$pos)
            use ($task, $plen, $cprefix, $cprefixlen) {
          // "// XXX\npublic $foo;"
          if (!substr_compare($str, $task->prefix, $pos, $plen)) {
            $pos += $plen;
            return true;
          } elseif ($cprefixlen and
                    !substr_compare($str, $cprefix, $pos, $cprefixlen) and
                    strpbrk($str[$pos + $cprefixlen], "\r\n")) {
            // "// XXX\n//\n// continuation"
            $pos += $cprefixlen;
            return true;
          }
        };

        // Skip over \r\n.
        $pos = $nextLine($task->jsonEndOffset ?? $task->endLineEnd, $llen);
        if ($jsonPos = $task->jsonEndOffset ?? null and
            $pos !== $jsonPos /*not EOF*/) {
          $task->commentOffsets[] = [$jsonPos, $pos];
          $task->commentLines[] = $task->jsonEndLine;
          $task->comment[] = $substrE($jsonPos, $pos);
        }

        for ($i = $start + 1; $i < $count and $tasks[$i]->inline; $i++) ;

        $nextDedicatedLine = $i < $count ? $tasks[$i]->startLineStart : PHP_INT_MAX;

        $el = end($task->commentLines);

        while (($pos += $llen) < strlen($str) and $pos < $nextDedicatedLine and
               $testPrefix($str, $pos)) {
          $task->commentOffsets[] = [$pos, $end = $nextLine($pos, $llen)];
          $task->commentLines[] = ++$el;
          $task->comment[] = $substrE($pos, $pos = $end);
        }

        $task->trailer = [];
        $left = $trailerCount;

        while ($pos < strlen($str) and $pos < $nextDedicatedLine and $left--) {
          $line = $substrE($pos, $end = $nextLine($pos, $llen));
          if (strspn($line, static::WHITESPACE) === strlen($line)) {
            break;
          }
          $task->trailerOffsets[] = [$pos, $end];
          $task->trailerLines[] = ++$el;
          $task->trailer[] = $line;
          $pos = $end + $llen;
        }

        if ($task->trailer) {
          $sl = $task->startLine;

          for (
            $i = $start - 1;
            $i >= 0 and !$tasks[$i]->inline and
            // $commentLines is [] if the entry consisted of "// XXX\n" line
            // that was dropped at the end of the cycle.
            ($tasks[$i]->commentLines ? end($tasks[$i]->commentLines)
              : $tasks[$i]->startLine) === $sl - 1;
            $i--
          ) {
            $i === $start - 1 and $task->blockGroup[] = $start;
            $tasks[$i]->blockGroup = &$task->blockGroup;
            array_unshift($task->blockGroup, $i);
            $sl = $tasks[$i]->startLine;
          }
        }
      }

      // Dedicated always comes before inline, meaning $start is always
      // dedicated or inline while $start+1..$end are inline.
      $end = $start;
      $el = empty($tasks[$start]->trailer) ? end($tasks[$start]->commentLines)
        : end($tasks[$start]->trailerLines);
      while (($tasks[++$end]->startLine ?? PHP_INT_MAX) <= $el) ;

      $slice = array_reverse(array_slice($tasks, $start, $end - $start), true);

      // The big-O of this is O-big but such is life.
      foreach ($slice as $si => $task) {
        $task->ownChunk = $si;
        $props = [['comment', $task->commentOffsets]];
        empty($task->trailer) or $props[] = ['trailer', $task->trailerOffsets];

        foreach ($props as [$prop, $offsets]) {
          foreach ($task->$prop as $i => $line) {
            list($pos0, $pos1) = $offsets[$i];
            $split = [$line];

            foreach ($slice as $other) {
              if ($other->startOffset >= $pos0 and $other->endOffset <= $pos1) {
                array_splice($split, 0, 1, [
                  substr($split[0], 0, $other->startOffset - $pos0),
                  substr($split[0], $other->endOffset - $pos0),
                ]);
              }
            }

            // $html = '';
            // foreach ($task->commentChunks as $i => $s) {
            //   $i and $html .= ($task->ownChunk === $i - 1 ? ... : ...);
            //   $html .= htmlspecialchars($s);
            // }
            $task->{$prop.'Chunks'}[] = $split;
          }

          for (
            ;
            $i >= 0 and count($split) === 1 and
            strspn($split[0], static::WHITESPACE) === strlen($split[0]);
            $i--
          ) {
            // Drop blank trailing comment lines from ...Chunks: "//XXX\n//".
            // Keeping in other arrays to allow determining precise end of XXX
            // block.
            array_pop($task->{$prop.'Chunks'});
            $split = end($task->{$prop.'Chunks'});
          }
        }

        // If a dedicated XXX block is the only thing on the line, drop that
        // line.
        if (!$si and !$task->inline and
            ltrim(substr($task->comment[0], $task->endColumn -
                         strlen($task->prefix)), static::WHITESPACE) === '') {
          array_shift($task->comment);
          array_shift($task->commentLines);
          array_shift($task->commentOffsets);
          array_shift($task->commentChunks);
        }
      }

      $start = $end;
    }

    return $tasks;
  }

  function takeOver(array $argv = null) {
    set_error_handler(function ($severity, $msg, $file, $line) {
      throw new ErrorException($msg, 0, $severity, $file, $line);
    }, -1);

    // We use mt_rand() to generate temporary file names and since it's
    // initialized to current time by default, two simultaneous requests to our
    // script may result in duplicate names.
    mt_srand(random_int(0, PHP_INT_MAX));

    // <?php
    // chdir('/var/cache');
    // $this->readOnly = true;
    if (is_file('noXXXep-config.php')) {
      include 'noXXXep-config.php';
    } elseif (is_file('../noXXXep-config.php')) {
      include '../noXXXep-config.php';
    }

    if ($argv) {
      $this->parseArgv($argv);
      exit($this->runCLI());
    } else {
      $this->runWeb();
    }
  }

  function parseArgv(array $argv) {
    // Not implemented.
  }

  function runCLI() {
    throw new Exception('CLI is not implemented.');
  }

  function runWeb() {
    switch ($do = $_REQUEST['do'] ?? '') {
      case 'css':
      case 'javascript':
        $user = $this->mediaPath.($do === 'javascript' ? 'js' : $do);
        is_file($user) or $user = null;
        $time = max(getlastmod(), $user ? filemtime($user) : 0);
        if ($this->ifModifiedSince($time)) { return; }
        header("Content-Type: text/$do; charset=utf-8");
        $h = fopen(__FILE__, 'rb');
        fseek($h, __COMPILER_HALT_OFFSET__);
        while (!feof($h) and rtrim(fgets($h)) !== $do) ;
        while (!feof($h) and !preg_match('/^[a-z]+$/', $line = fgets($h))) {
          echo $line;
        }
        fclose($h);
        $user and readfile($user);
        return;
      default:
        return $this->{'do_'.$do}();
    }
  }

  // Handles conditional caching based on Last-Modified. Returns true and 304
  // status if further request processing is unnecessary.
  //
  // Using Etag based on $cache->unique/epoch may seem better in some situations
  // (such as when cache is refreshed multiple times per second, given
  // Last-Modified's precision is one second). However, Etag is subject to
  // Content-Encoding and at least nginx removes it in proxied (php-fpm)
  // response that it has compressed.
  function ifModifiedSince($time = null) {
    $time = date(DATE_RFC7231, func_num_args() ? $time : $this->cache->time);
    if ($time === ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null)) {
      http_response_code(304);
      return true;
    }
    header('Last-Modified: '.$time);
  }

  protected function do_cache() {
    $this->refreshIfNeeded();
    if ($this->ifModifiedSince()) { return; }
    header("Content-Type: text/javascript; charset=utf-8");
    echo 'var noXXXep = ';
    echo json_encode($this->cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  protected function do_launch() {
    if ($this->readOnly) {
      throw new Exception('Read-only mode active.');
    }
    $file = escapeshellarg($this->rootPath().$_REQUEST['file']);
    if ($_REQUEST['start'] ?? null and $this->launchSelection) {
      $cmd = sprintf($this->launchSelection, $_REQUEST['start'],
                     $_REQUEST['end'], $file);
    } elseif ($_REQUEST['line'] ?? null and $this->launchLine) {
      $cmd = sprintf($this->launchLine, $_REQUEST['line'], $file);
    } elseif ($_REQUEST['find'] ?? null and $this->launchFind) {
      $cmd = sprintf($this->launchFind, escapeshellarg($_REQUEST['find']), $file);
    } else {
      $cmd = sprintf($this->launchFile, $file);
    }
    // popen() + start is the only way to run something in background on
    // Windows. In *nix, exec('cmd >/dev/null &'); also does the job.
    pclose(popen($cmd, 'r'));
    // Retain the page from which user has called launch.
    http_response_code(204);
  }

  // Can call this multiple times in succession without refreshing cache as long
  // as each call changes different files (this condition will be detected by
  // comparing hashes).
  protected function do_change() {
    if ($this->readOnly) {
      throw new Exception('Read-only mode active.');
    }

    $this->cache = $this->readCache();
    list($locator, $file, $task) = $this->locateTask($_REQUEST['locator']);

    if ($priority = $_REQUEST['priority'] ?? null) {
      // Errors on invalid key.
      $task->match[1] = array_flip($this->priorities)[$priority];
      $task->priority = $priority;
    }

    if ($id = $_REQUEST['id'] ?? null) {
      if (isset($this->cache->ids[$id])) {
        throw new Exception('ID already in use');
      }
      $task->match[4] = $id;
      $task->id = $id;
    }

    foreach ([2 => 'tags', 3 => 'groups'] as $i => $prop) {
      if ($value = $_REQUEST[$prop] ?? null) {
        $task->$prop = $value = array_filter($value, 'strlen');
        $task->match[$i] = join(',', $value);
      }
    }

    $data = file_get_contents($this->rootPath().key($file->paths));

    $data = join([
      substr($data, 0, $task->startOffset),
      $this->taskLine($task),
      !isset($task->jsonEndOffset) ? '' :
        substr($data, $task->endOffset, $task->jsonStartOffset - $task->endOffset),
      // Changing of $task->options via do=change is currently not supported,
      // therefore preserving user's JSON format string.
      //join("\n", (array) $this->taskJSON($task)),
      $task->jsonRaw ?? '',
      substr($data, $task->jsonEndOffset ?? $task->endOffset),
    ]);

    $paths = array_keys($file->paths);

    if (isset($_REQUEST['paths'])) {
      if (count(array_intersect($paths, $_REQUEST['paths'])) !== count($_REQUEST['paths'])) {
        throw new Exception('Requested paths differ.');
      }
      $paths = $_REQUEST['paths'];
    }

    foreach ($paths as $path) {
      $realpath = $this->skipByRealpath ? $this->cache->paths[$path]->realpath : null;
      $this->writeSource($path, $data, $locator[0], $realpath);
    }
  }

  function locateTask($locator) {
    is_array($locator) or $locator = explode(',', $locator);
    $file = $this->cache->files[$locator[0]];
    $task = $file->tasks[$locator[1]];

    if ($file->unique !== (int) $locator[2] or !$file->paths) {
      throw new Exception('File and cache changed since page generation.');
    }

    return [$locator, $file, $task];
  }

  protected function taskLine(stdClass $task) {
    $p1 = $task->priority !== $this->defaultPriority;
    $p2 = $task->tags;
    $p3 = $task->groups;
    $p4 = $task->id !== '';
    $p5 = $task->options;
    $p6 = $task->wrapped;

    $line = 'XXX';
    if ($p1 or $p2 or $p3 or $p4 or $p5 or $p6) {
      $line .= $task->match[1];
    }
    if ($p2 or $p3 or $p4 or $p5 or $p6) {
      $line .= $task->match[2];
    }
    if ($p3 or $p4 or $p5 or $p6) {
      $line .= ':'.$task->match[3];
    }
    if ($p4 or $p5 or $p6) {
      $line .= ' '.$task->match[4];
    }
    if ($p5 or $p6) {
      $line .= ' '.$task->match[5];
    }
    $line .= $p6 ? $task->match[6] : ($p3 || $p4 || $p5 ? ':' : '');

    return $line;
  }

  protected function taskJSON(stdClass $task) {
    $quote = function ($s) {
      if (!is_scalar($s) or strspn($s, static::ID) !== strlen($s) or
          in_array($s, ['true', 'false', 'null'])) {
        // "Easy" JSON is currently one level only. It should be easy enough to
        // make it recursive, if desired.
        $s = json_encode($s);
      }
      return $s;
    };

    if ($task->jsonKeys ?? []) {
      $col = 76 - strlen($task->prefix);
      $lines = ['{'];

      foreach ($task->jsonKeys as $key) {
        $line = $quote($key).': '.$quote($task->options[$key]);
        if ($lines[0] === '{') {
          // No comma.
        } elseif (strlen($line) > $col) {
          $lines[0] .= ',';
          array_unshift($lines, '');
        } else {
          $lines[0] .= ', ';
        }
        $lines[0] .= $line;
      }

      $lines[0] .= '}';
      return array_map(function ($s) use ($task) { return $task->prefix.$s; },
                       array_reverse($lines));
    }
  }

  protected function do_bareTasks() {
    $this->refreshIfNeeded();
    if ($this->ifModifiedSince()) { return; }
    $this->filters = $_REQUEST;
    $this->formatTasks();
  }

  protected function do_() {
    $this->refreshIfNeeded();
    if ($this->ifModifiedSince()) { return; }
    $this->filters = $_REQUEST;
    // Allow ?tag=single rather than ?tag[]=single for brevity.
    foreach ($this->filters as &$ref) { $ref = (array) $ref; }
    $count = count($this->filterTasks($this->filters));

    $title = [];
    foreach ($this->filters as $filter => $values) {
      switch (count($values)) {
        case 1:
          $title[] = "$filter[0]:".end($values);
          break;
        default:
          $title[] = $filter;
        case 0:
      }
    }
    $title = (join(' • ', $title) ?: 'noXXXep')." — $this->title";
?>
  <!DOCTYPE html>
  <html>
    <head>
      <title><?=htmlspecialchars($title)?></title>

      <link rel="icon" href="data:image/gif;base64,R0lGODlhEAAQAJEAAP8AAP96eqQAAP///yH5BAEAAAMALAAAAAAQABAAAAI7nBOmg8sBHAPxQLouRhSr3mndAQoCOQ6dKYnUKX1pLHu0ayuiAVYcyOsBXIsXgKWBBY9KwVHCdLCkygIAOw==">

      <link rel="stylesheet" href="?do=css">
    </head>
    <body class="Xbody">
      <textarea id="Xcopy" style="position: absolute; left: -100em"></textarea>

      <!--script async src="?do=cache"></script-->
      <script async src="?do=javascript"></script>

      <form action="?" class="Xfilters" id="Xfilters">
        <?=$this->formatFilters()?>

        <div>
          <abbr title="Double-click in any list to clear others &amp; search, or press Enter to just search">?</abbr>
          <noscript>NoScript, NoCool</noscript>
          <button type="submit">
            Apply
            (<span class="Xcount"><?=$count?></span>)
          </button>
          <button type="reset">Reset</button>
          <a href="?">Show All</a>
          <?php if (!$this->tempFile) {?>
            <span class="Xwarn">
              <code>$tempFile</code> not defined and directory not writable
            </span>
          <?php } elseif ($this->writeCacheError) {?>
            <span class="Xwarn">Error writing cache file</span>
          <?php }?>
        </div>
      </form>

      <div id="XFilters__ph"></div>

      <script>
        function onresize() {
          XFilters__ph.style.height = Xfilters.offsetHeight + 'px'
        }
        var div = document.createElement('div')
        div.innerHTML = '<a class=Xfilters__float href=#>Filters</a>'
        Xfilters.parentNode.insertBefore(div.firstElementChild, Xfilters)
        Xfilters.previousElementSibling.onclick = function () {
          this.classList.toggle('Xfilters__float_pinned')
          return false
        }
        onresize()
        addEventListener('resize', onresize)
      </script>

      <?php if ($count) {?>
        <table class="Xtasks">
          <?php $this->formatTasks()?>
        </table>
      <?php } else {?>
        <div class="Xempty">No tasks found</div>
      <?php }?>

      <?php
        $timings = [];
        foreach ($this as $prop => $value) {
          if (substr($prop, 0, 5) === 'time_' and $value !== null) {
            $timings[substr($prop, 5)] = $value;
          }
        }

        $total = array_sum($timings);
        foreach ($timings as $prop => &$ref) {
          if ($ref > 0.001) {
            $ref = sprintf('%s (%.2fs, %d%%)', $prop, $ref, $ref / $total * 100);
          } else {
            $ref = $prop;
          }
        }
      ?>
      <footer class="Xfooter">
        <a href="https://github.com/ProgerXP/noXXXep">noXXXep – The Insideous Bug Tracker</a>
        |
        Timings (<?=round($total, 2)?>s):
        <?=htmlspecialchars(join(', ', $timings))?>
      </footer>
    </body>
  </html>
<?php
  }

  protected function formatFilters() {
    extract($this->filters, EXTR_PREFIX_ALL, 'f');

    $sort = function (array $a, $keys = true) {
      $keys and $a = array_keys($a);
      sort($a);
      return $a;
    };

    $sortNamed = function (array $a, array $names) use ($sort) {
      $first = array_filter(array_keys($names), function ($n) use ($a) {
        return isset($a[$n]);
      });
      $a = $sort(array_diff_key($a, $names));
      return array_merge($first, $a);
    };

    $tags = array_merge([''], $sortNamed($this->cache->tags, $this->tagNames));

    $groups = $this->cache->groups + $this->cache->orphans;
    unset($groups['']);
    $groups = $sortNamed($groups, $this->groupNames);
  ?>
    <label>
      Priority:
      <?=$this->formatSelect('priority', $this->priorities, $f_priority ?? [])?>
    </label>

    <label>
      Tags:
      <?=$this->formatSelect('tag', $tags, $f_tag ?? [], $this->tagNames + ['' => '(No tags)'])?>
    </label>

    <label>
      Groups:
      <?=$this->formatSelect('group', $groups, $f_group ?? [], $this->groupNames)?>
    </label>

    <label>
      File:
      <?=$this->formatSelect('path', $sort($this->cache->view->files, false), $f_path ?? [])?>
    </label>
  <?php
  }

  protected function formatSelect($name, array $options, array $current,
      array $names = []) {
    echo '<a href="#">*</a>';
    echo '<a href="#">~</a>';
    echo '<select name="', $name, '[]" multiple>';

    foreach ($options as $value) {
      $name = $names[$value] ?? $value;
      echo '<option ', in_array($value, $current) ? 'selected' : '';
      if ($value === $name) {
        echo '>', htmlspecialchars($name);
      } else {
        echo ' value="', htmlspecialchars($value), '">';
        if ($value === '') {
          // Show just $name.
        } elseif (!strncmp($name, $value, strlen($value))) {
          $name = "$value)".substr($name, strlen($value));
        } else {
          $name = "$value) $name";
        }
        echo htmlspecialchars($name);
      }
      echo '</option>';
    }

    echo '</select>';
  }

  protected function formatTasks() {
    $time = microtime(true);
    $groups = [];
    foreach ($this->filterTasks($this->filters) as $task) {
      $task->filtered = true;
      $groups += array_flip($task->groups ?: ['']);
    }
    while ($current = $groups) {
      $groups = [];
      $this->matchingGroups += $current;
      foreach ($current as $group => $v) {
        if ($group = $this->cache->ids[$group] ?? null) {
          $groups += array_flip($this->locateTask($group)[2]->groups ?: ['']);
        }
      }
    }
    $this->time_filterTasks = microtime(true) - $time;

    $time = microtime(true);
    //$this->formatGroups($this->cache->groups);
    $this->formatGroups($this->cache->orphans, true);
    $this->time_formatTasks = microtime(true) - $time;
  }

  protected function formatGroups(array $groups, $headers = false) {
    ksort($groups);

    foreach ($groups as $id => $group) {
      if (array_key_exists($id, $this->matchingGroups)) {
        if ($headers and $id !== '') {
          $idq = htmlspecialchars($id);
          echo '<tr id="', $idq, '" class="Xtasks__group">';
          echo '<td colspan="4"><a href="#', $idq, '">#', $idq, '</a></td></tr>';
        }

        $this->formatGroup($group);
      }
    }
  }

  protected function formatGroup(array $group, array $parents = []) {
    usort($group, function (array $a, array $b) {
      if ($a[0] === $b[0]) {
        return $a[1] - $b[1];
      } else {
        return strcmp(
          key($this->cache->files[$a[0]]->paths),
          key($this->cache->files[$b[0]]->paths)
        );
      }
    });

    foreach ($group as $locator) {
      list(, $file, $task) = $this->locateTask($locator);
      if (in_array($task->id, $parents)) {
        echo '<tr class="Xtasks__rec"><td colspan="4">Circular group: ';
        echo htmlspecialchars(join(' → ', $parents)), '</td></tr>';
      } else if ($this->isTaskOutput($task)) {
        $this->formatTask($task, $locator, $parents);
      }
    }
  }

  protected function isTaskOutput(stdClass $task) {
    return !empty($task->filtered) or
           ($task->id !== '' and array_key_exists($task->id, $this->matchingGroups));
  }

  protected function filterTask(stdClass $file, stdClass $task, array $filters) {
    extract($filters, EXTR_PREFIX_ALL, 'f');
    $noTags = in_array('', $f_tag ?? []);
    return (!isset($f_priority) or in_array($task->priority, $f_priority)) and
           (!isset($f_tag) or array_intersect($task->tags, $f_tag) or
            ($noTags and !$task->tags)) and
           (!isset($f_group) or array_intersect($task->groups, $f_group) or
            // If user is filtering by a group, implicitly match the group
            // itself (i.e. task with ID being one of the filtered groups) -
            // assuming that's what the user wants. do_() would include such
            // task (and its parents) into result anyway, but mark it
            // Xtask_forced.
            in_array($task->id, $f_group)) and
           (!isset($f_path) or array_intersect(array_keys($file->paths), $f_path));
  }

  protected function formatTask(stdClass $task, array $locator, array $parents = []) {
    $trailer = (isset($task->blockGroup)
      ? $this->locateTask(end($task->blockGroup))[2]
      : $task)->trailer ?? [];
    $class = [];
    empty($task->filtered) and $class[] = 'Xtask_forced-filter';
    if ($task->inline or (!$task->commentChunks and $trailer)) {
      $class[] = 'Xtask_inline';
    }
    //$parents and $class[] = 'Xtask_depth_'.count($parents);
    if ($task->priority !== static::NORMAL) {
      $class[] = 'Xtask_prio_'.$task->priority;
    }
    foreach (array_merge($task->tags, $task->groups) as $i => $s) {
      $n = $i < count($task->tags) ? 't' : 'g';
      strspn($s, static::ID) === strlen($s) and $class[] = "X{$n}__$s";
    }
    $blockGroup = join(',', $task->blockGroup[0] ?? []);
    $anchor = $task->id !== '' ? $task->id
      : ($blockGroup && $task->blockGroup[0] === $locator ? $blockGroup : '');
  ?>
    <tr id="<?=htmlspecialchars($anchor)?>"
        class="<?=htmlspecialchars(join(' ', $class))?>"
        data-locator="<?=htmlspecialchars(join(',', $locator))?>">
      <td></td>
      <td>
        <?php if ($task->id !== '') {?>
          <a href="#<?=htmlspecialchars($task->id)?>"><?=htmlspecialchars("#$task->id")?></a>
        <?php }?>
      </td>
      <td>
        <?php
          foreach ($task->tags as $i => $tag) {
            echo $i ? ', ' : '';
            echo '<a href="?tag[]=', htmlspecialchars(rawurlencode($tag)), '"';
            if (strlen($name = $this->tagNames[$tag] ?? '')) {
              echo ' title="', htmlspecialchars($name), '"';
            }
            echo '>', htmlspecialchars($tag), '</a>';
          }
        ?>
      </td>
      <td
        <?php if ($parents) {?>
          style="border-right-width: <?=count($parents) * 0.25?>em"
        <?php }?>
      >
        <div>
          <?php
            echo '<span>';

            foreach ($parents as $id) {
              echo '<a href="#', htmlspecialchars($id), '">↳</a>';
            }

            echo ' ';

            if ($blockGroup) {
              if ($task->blockGroup[0] === $locator) {
                echo '<b>', htmlspecialchars($locator[1]), '</b>';
              } else {
                list(, , $gtask) = $this->locateTask($task->blockGroup[0]);
                echo '<a href="',
                     $this->isTaskOutput($gtask) ? '' : '?',
                     '#', $gtask->id !== '' ? $gtask->id : $blockGroup,
                     '">', htmlspecialchars($task->blockGroup[0][1]), '</a>';
              }
            }

            echo '</span> ';

            //echo htmlspecialchars(join(' ', $task->comment))

            if (!$task->commentChunks) {
              if ($trailer) {
                echo htmlspecialchars($trailer[0]);
              } else {
                echo '<i>';
                foreach ($task->tags as $tag) {
                  if (isset($this->tagNames[$tag])) {
                    echo htmlspecialchars($this->tagNames[$tag]), ' • ';
                  }
                }
                echo htmlspecialchars(key($this->locateTask($locator)[1]->paths));
                echo '</i>';
              }
            }

            $idsRE = $this->cache->view->idsRE;
            $idsRE = $idsRE ? "/\b($idsRE)\b/u" : '/.^/';

            foreach ($task->commentChunks as $num => $line) {
              echo ' ';

              foreach ($line as $i => $s) {
                if ($i) {
                  echo !$num && $task->ownChunk === $i - 1
                    ? strlen($line[$i - 1]) ? '<u>XXX</u>' : ''
                    : '<span>XXX</span>';
                }

                echo preg_replace_callback(
                  $idsRE,
                  function ($m) {
                    $id = $m[1];
                    list(, , $task) = $this->locateTask($this->cache->view->ids[$id]);
                    $base = $this->isTaskOutput($task) ? '' : '?';
                    return '<a href="'.$base.'#'.$id.'">'.$id.'</a>';
                  },
                  htmlspecialchars($s)
                );
              }
            }

            echo '&nbsp;<span>', $trailer ? '⋯' : '¶', '</span>';
          ?>
        </div>
      </td>
    </tr>
    <?=$this->formatGroup($this->cache->groups[$task->id] ?? [], array_merge($parents, [$task->id]))?>
  <?php
  }

  protected function do_count() {
    $this->refreshIfNeeded();
    echo count($this->filterTasks($_REQUEST));
  }

  protected function filterTasks(array $filters) {
    $res = [];

    foreach ($this->cache->files as $file) {
      if ($file->paths) {
        foreach ($file->tasks as $task) {
          $this->filterTask($file, $task, $filters) and $res[] = $task;
        }
      }
    }

    return $res;
  }

  protected function do_taskInfo() {
    $this->cache = $this->readCache();
    if ($this->ifModifiedSince()) { return; }
    list(, $file, $task) = $this->locateTask($_REQUEST['locator']);

    foreach ($file->paths as $path => $i) {
      echo '<p class="Xfile">';
      echo '<a class="Xfind" href="?path[]=';
      echo htmlspecialchars(rawurlencode($path)), '">Filter</a>';
      if ($this->fileURL and $url = call_user_func($this->fileURL, $file, $task)) {
        echo '<a class="Xfind" href="', htmlspecialchars($url), '">View</a>';
      }
      if ($this->readOnly) {
        // Leading space prevents double click + drag over the file:line string
        // from selecting preceding content (such as View or Filter).
        echo ' <a href="#" data-copy>', htmlspecialchars($path), '</a>';
        echo ':<a href="#" data-copy>', $task->startLine + 1, '</a>';
      } else {
        $query = ['do' => 'launch', 'file' => $path,
                  'start' => $task->startOffset,
                  'end' => $task->endOffset,
                  // If the editor doesn't support opening by offset.
                  'line' => $task->startLine + 1];
        echo '<a class="Xfile__launch" href="?';
        echo htmlspecialchars(http_build_query($query, '', '&')), '">';
        echo htmlspecialchars("$path:"), $task->startLine + 1;
        echo '</a>';
      }
      echo '</p>';
    }

    // Display trailer of last task in block group since all tasks in a group
    // are before a single code snippet (or none, but not multiple).
    if (isset($task->blockGroup)) {
      $task = $this->locateTask(end($task->blockGroup))[2];
    }

    foreach ([/*'comment',*/ 'trailer'] as $prop) {
      $chunks = $task->{$prop.'Chunks'} ?? [];
      $indent = PHP_INT_MAX;

      foreach ($chunks as $line) {
        $indent = min($indent, strspn($line[0], static::WHITESPACE));
      }

      foreach ($chunks as $num => $line) {
        if (empty($tableStarted)) {
          echo $tableStarted = '<table class="Xcode">';
        }

        echo '<tr>';
        echo '<th data-n="', $task->{$prop.'Lines'}[$num], '"></th>';
        echo '<td>';

        foreach ($line as $i => $s) {
          if (!$i) {
            $s = substr($s, $indent);
          } elseif (!$num and $task->ownChunk === $i - 1) {
            echo '<u>XXX</u>';
          } else {
            echo '<span>XXX</span>';
          }

          echo htmlspecialchars($s);
        }

        echo '</td>';
        echo '</tr>';
      }
    }

    if (!empty($tableStarted)) {
      echo '</table>';
    }
  }
}

count(get_included_files()) < 2 and (new noXXXep)->takeOver($argv ?? null);

__halt_compiler();
?>

<script>
javascript

function ajax(url, done, error) {
  var xhr = new XMLHttpRequest

  xhr.onreadystatechange = function () {
    if (xhr.readyState == 4) {
      xhr.status >= 200 && xhr.status < 300 ? done(xhr) : (error && error(xhr))
    }
  }

  xhr.open('GET', url, true)
  xhr.send(null)
}

var onchangeXHR
var onchangeTimer

function onchange(e) {
  if (e !== this) {
    onchangeXHR && onchangeXHR.abort()
    clearTimeout(onchangeTimer)
    return onchangeTimer = setTimeout(onchange.bind(e, e), 50)
  }

  var query = []

  document.querySelectorAll('.Xfilters select').forEach(function (sel) {
    sel.querySelectorAll('option').forEach(function (opt) {
      if (opt.selected) {
        query.push(encodeURIComponent(sel.name) + '=' + encodeURIComponent(opt.value))
      }
    })
  })

  document.querySelector('.Xcount').innerHTML = '&hellip;'

  onchangeXHR = ajax('?do=count&' + query.join('&'), function (xhr) {
    document.querySelector('.Xcount').innerHTML = xhr.response
  })
}

document.body.addEventListener('change', onchange)
document.body.addEventListener('reset', onchange)

document.body.addEventListener('keydown', function (e) {
  if (e.target.tagName == 'SELECT' && e.keyCode == 13) {
    Xfilters.submit()
  }
})

document.body.addEventListener('dblclick', function (e) {
  if (e.target.tagName == 'OPTION') {
    Array.prototype.forEach.call(Xfilters.querySelectorAll('select'),
      function (sel) {
        sel == e.target.parentNode || (sel.selectedIndex = -1)
      })
    Xfilters.submit()
  }
})

document.body.addEventListener('click', function (e) {
  if (e.target.hasAttribute('data-copy')) {
    Xcopy.value = e.target.textContent.trim()
    Xcopy.select()
    document.execCommand('copy')
    return e.preventDefault()
  }

  if (e.target.tagName == 'A') {
    var sel = e.target.nextElementSibling
    while (sel && sel.tagName != 'SELECT') {
      sel = sel.nextElementSibling
    }

    if (sel) {
      var some = Array.prototype.some.call(sel.options, function (o) { return o.selected })
      var mode = e.target.textContent == '~' ? -1 : !some

      Array.prototype.forEach.call(sel.options, function (o) {
        o.selected = mode == -1 ? !o.selected : mode
      })

      onchange(e)
      return e.preventDefault()
    }
  }

  if (e.target.tagName != 'A' && e.target.parentNode.tagName == 'DIV' &&
      !e.target.parentNode.previousElementSibling) {
    var td = e.target.parentNode.parentNode
  } else if (e.target.tagName == 'DIV' && !e.target.previousElementSibling) {
    var td = e.target.parentNode
  } else if (e.target.tagName == 'TD' && !e.target.nextElementSibling) {
    var td = e.target
  }

  if (td && td.tagName == 'TD' &&
      td.parentNode.hasAttribute('data-locator') &&
      td.parentNode.parentNode.parentNode.classList.contains('Xtasks')) {
    if (td.classList.toggle('Xexpanded')) {
      if (td.noXXXep) {
        return td.appendChild(td.noXXXep)
      }

      var div = document.createElement('div')
      div.className = 'Xinfo Xloading'
      td.appendChild(div)

      ajax(
        '?do=taskInfo&locator=' + encodeURIComponent(td.parentNode.getAttribute('data-locator')),
        function (xhr) {
          if (div.parentNode) {
            div.innerHTML = xhr.response
            div.classList.remove('Xloading')
          }
        },
        function (xhr) {
          div.className = 'Xerror'
          div.innerHTML = xhr.statusText || 'Network Error'
        }
      )
    } else {
      td.removeChild(td.noXXXep = td.lastElementChild)
    }
  }
})

css
.Xfilters,
.Xbody {
  background: #eee;
}

.Xfilters__float:not(:hover),
.Xfilters a:not(:hover),
.Xtasks a:not(:hover),
.Xfooter a:not(:hover) {
  text-decoration: none;
}

.Xfooter {
  text-align: center;
  margin-top: 1em;
  color: silver;
}

.Xfooter a:not(:hover) {
  color: gray;
}

.Xfilters {
  text-align: center;
  margin-bottom: 1em;
}

.Xfilters__float {
  position: fixed;
  z-index: 2;
  color: blue;
  text-shadow: .1em .1em white;
}

.Xfilters__float_pinned {
  color: #551A8B;
}

.Xfilters__float + .Xfilters {
  position: absolute;
  padding: .5em /*body margin-top*/ 0;
  top: 0;
  left: 0;
  right: 0;
}

.Xfilters__float_pinned + .Xfilters,
.Xfilters__float:hover + .Xfilters,
.Xfilters__float + .Xfilters:hover {
  position: fixed;
  border-bottom: .1em solid silver;
  background: linear-gradient(0deg, #eaeaea, #eee);
}

.Xfilters label {
  display: inline-block;
  margin: 0 .5em .5em 0;
}

.Xfilters label a {
  float: left;
  padding: .4em .4em 0;
}

.Xfilters label a + a {
  float: right;
}

.Xfilters select {
  display: block;
  min-width: 12em;
  height: 10em;
  border: .1em solid silver;
  margin-top: .2em;
}

.Xfilters .Xwarn, .Xfilters noscript {
  background: orange;
  padding: .1em .3em;
}

.Xempty {
  text-align: center;
  border: dashed silver;
  border-width: 0.06em 0;
  padding: .5em;
  background: #e5e5e5;
}

.Xtasks {
  border-spacing: 0;  /* border-collapse doesn't play with border-radius */
  background: white;
  box-shadow: 0 0 1em #ccc;
  border-radius: .5em;
  margin: 0 auto;
  min-width: 30em;
}

.Xtasks__group {
  background: #f1f1f1;
}

.Xtask_forced-filter:not(:hover) {
  opacity: .35;
}

.Xtasks a[title]:not(:hover) {
  text-decoration: underline dotted;
}

.Xtasks > tbody > :target > :last-child:before {
  float: right;
  content: "←";
  width: 1.5em;
  height: 1.5em;
  text-align: center;
  color: red;
  background: white;
  border: .06em solid;
  border-radius: 100%;
}

.Xfilters label a,
.Xtask_inline > :nth-child(4) > :first-child,
.Xinfo td {
  font-family: monospace;
  color: navy;
}

.Xtasks > tbody > * > :nth-child(4) > :first-child > span {
  color: silver;
}

.Xtasks > tbody > * > :nth-child(4) :not(span) > a {
  text-decoration: underline;
}

.Xtasks > tbody > * > :nth-child(4) :not(span) > a:hover {
  text-decoration: none;
}

.Xtasks > tbody > :first-child > :first-child { border-top-left-radius: .5em; }
.Xtasks > tbody > :first-child > :last-child  { border-top-right-radius: .5em; }
.Xtasks > tbody > :last-child > :first-child  { border-bottom-left-radius: .5em; }
.Xtasks > tbody > :last-child > :last-child   { border-bottom-right-radius: .5em; }

.Xtasks > tbody > tr > * {
  border: .06em solid #ddd;
  padding: .25em .5em;
}

/* Also for [colspan]. */
.Xtasks > tbody > tr > :first-child {
  text-align: center;
}

.Xtask_prio_highest > :first-child { color: white; background: red; }
.Xtask_prio_highest > :last-child  { border-right: .18em solid red; }
.Xtask_prio_high > :first-child    { color: white; background: maroon; }
.Xtask_prio_high > :last-child     { border-right: .18em solid maroon; }
.Xtask_prio_low > :first-child     { color: white; background: gray; }
.Xtask_prio_low > :last-child      { border-right: .18em solid gray; }
.Xtask_prio_lowest > :first-child  { color: white; background: silver; }
.Xtask_prio_lowest > :last-child   { border-right: .18em solid silver; }

.Xtask_prio_highest > :first-child:before { content: "⇈"; }
.Xtask_prio_high    > :first-child:before { content: "↑"; }
.Xtask_prio_low     > :first-child:before { content: "↓"; }
.Xtask_prio_lowest  > :first-child:before { content: "⇊"; }

/* Courtesy of http://ajaxloadingimages.net. */
.Xloading {
  background: url("data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAHgCAYAAADE5W/0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAUdSURBVHhe7ZXBbuQwDEP7/z+9Cx0ECK4d0V1Kyk75AMFpZkI+zSH9EuJ/4s8PhsptIF3AiNtlUwIaLIFyAfQsYbTcGS13Rsud0XLjFds7rRKnshaJrKRUAg0vk5DAKwTQoXMbSpeI26EjhBCCyu5Vmw2V20C6gBG3y6YENFgC5QLr6ZzuUzlJtJQ7o+XOaLkzWm68YnunVSKWna7LyEpKJdDwMgkJvEIAHTq3oXSJuB06QgghqOxetdlQuQ2kCxhxu2xKQIMlUC5wKsg+p7CWnM5SRsud0XJntNx4xfZOq0QsO12XkZWUSuzC0XsU0GAJlAqgQ+c2lC4Rt0NHCCEEld2rNhsqt4F0ASNul00JaLAEygWygjIBY5U4naWMljuj5c5oufGK7Z1WiVh2ui4jKymV2IWj9yigwa0Cv+8XQIfObShdIm6HjhBCCCq7V202VG4D6QJG3C6bEtBgCXyugOHh2VnKaLkzWu6Mlhuv2N5plYhlp+syspJSiV04eo8CGtwqMPoLnIpKBdChcxtKl4jboSOEEILK7lWbDZXbQLqAEbfLpgQ0WAKfK2B4+Fpyul/CWtZa7oyWO6Plxiu2d1olYtnpuoyspFRiF47eo4AGtwqM/gL+9+k+HQtGh85tKF0iboeOEEIIKrtXbTZUbgPpAkbcLpsS0GAJfK6A4eGnktJyZ5XIpEoYLXdGy41XbO+0SsSy03UZWUmpxC4cvUcBDW4VGPkFTqdTKoAOndtQukTcDh0hhBBUdq/abKjcBtIFjLhdNiWgwRL4XAEjCy8td7zkdLYwWu6Mlhuv2N5plYhlp+syspJSiV04eo8CGtwqMPILoCcdC0aHzm0oXSJuh44QQggqu1dtNlRuA+kCRtwumxLQYAl8roCRhZeWO16ylrWUO6tEa7kzWm68YnunVSKWna7LyEpKJXbh6D0KaHCLgF/vysoF0JOOBaND5zaULhG3Q0cIIQSV3as2Gyq3gXQBI26XTQlosAQ+V8DIwkvLnVNJS7njZevZymi58YrtnVaJWHa6LiMrKZXYhaP3KKDBLQJ+vZ5GuQB60rFgdOjchtIl4nboCCGEoLJ71WZD5TaQLmDE7bIpAQ2WwOcKGFl4ablzKmkpd9ay1nLHS0fKjVGBtbRVIpadrsvISkolduHoPQpr8KmoRcCv19MoF0BPOhaMDp3bULpE3A4dIYQQVHav2myo3AbSBYy4XTYloMES+FwBIwsvLXdOJS3lzlrWWu546Ui5MSqwlrZKnMpaJLKSUoldOHqPwhrsf5/u04nBa/nuMzqn0tNJx4LRoXMbSpeI26EjhBCilfHXr5ePScTidond9m0Sa1GrxKmgTeIpvEUiCy6XQELtOz50slBqadzE54ns8yuQsnVoZGHUMmfdZD0ju3v/TAzNJEoEDETCzvg9OjF8Vx7PMhCJcp4k2thJtDO2fcTKRwWEEEKI3wH7X+51nn/ZH4yTcXrGT4inh56Cnr7/9Nw3soeeilayrC3IQ/Ez5HtP3/kG+pB9jnwnnhA/eugAReDp9OsT6zMQu4LTacTrld33U9aHTmdkd894euZIfGgNeArafYY894340DoZp2f8hIgPMmDnCSGEEGKW0f/tXjwiEEvbBXblbRJPm5dLPJU7ZRJIuVEigJT7/TIBn8hauvsOnV3p7iwFkViHzm1J9jmFcQmkwL7jQycLjZ+3C5SXG6fglnJnLWgtd7xopNwZLTd2v0IrVjxWLsQP+fr6C9L+WO7AiETiAAAAAElFTkSuQmCC");
  animation: hourglass 1.5s steps(15) infinite;
  width: 32px;
  height: 32px;
}
@keyframes hourglass { from { background-position: 0 0; } to { background-position: 0 -480px; } }

.Xerror {
  color: red;
}

html td.Xexpanded {
  border-color: gray;
}

.Xfind {
  float: left;
  padding-right: .5em;
}

.Xfile {
  margin: .2em 0;
}

.Xfile__launch {
  display: block;
}

.Xcode {
  border-collapse: collapse;
  line-height: 1em;
  margin-top: .4em;
  background: #f5f5f5;
}

.Xinfo tr:first-child > * { padding-top: .25em; }
.Xinfo tr:last-child > * { padding-bottom: .25em; }

.Xinfo tr > * {
  padding: 0 .5em;
}

.Xinfo th {
  vertical-align: top;
  border-right: .06em solid navy;
  color: gray;
  font-weight: normal;
  text-align: right;
}

.Xinfo th:before {
  /*
    This hides line numbers from Ctrl+C. One disadvantage is that there are no
    numbers when viewed with CSS off (in FF); this could be solved by outputting
    them directly and adding user-select: none; but in FF that causes
    indentation on the last copied incomplete line to be lost.
  */
  content: attr(data-n);
}

.Xinfo td {
  padding-left: .5em;
  white-space: pre-wrap;
}

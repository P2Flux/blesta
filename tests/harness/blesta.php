<?php
/**
 * A minimal stand-in for the parts of Blesta the gateway touches, so the gateway runs against the real
 * P2Flux test API without a Blesta install. Not Blesta: shapes follow Blesta 5.x/6.0 core
 * (NonmerchantGateway, Record, Input, Language, Configure, Loader). State lives in a JSON file so a
 * webhook served by `php -S` and the CLI test see the same records.
 */
const DS = DIRECTORY_SEPARATOR;
define('ROOTWEBDIR', '/');

final class Configure
{
    public static array $v = [];
    public static function get($k) { return self::$v[$k] ?? null; }
    public static function set($k, $v) { self::$v[$k] = $v; }
}

final class Language
{
    public static function loadLang(...$a) {}
    public static function _($key, $return = false, ...$args)
    {
        $text = $args ? vsprintf($key . ' ' . str_repeat('[%s]', count($args)), $args) : $key;
        if ($return) {
            return $text;
        }
        echo $text;
    }
}

final class Loader
{
    public static function loadComponents($o, array $names)
    {
        foreach ($names as $n) {
            $o->$n = $n === 'Record' ? HarnessRecord::instance() : new HarnessInput();
        }
    }
    public static function loadModels($o, array $names)
    {
        foreach ($names as $n) {
            $o->$n = new HarnessModel($n);
        }
    }
    public static function loadHelpers(...$a) {}
}

final class HarnessInput
{
    public array $errors = [];
    private array $rules = [];
    public function setRules(array $r) { $this->rules = $r; }
    public function setErrors(array $e) { $this->errors = $e; }
    public function errors() { return $this->errors ?: false; }
    public function validates(array $v): bool
    {
        foreach ($this->rules as $field => $checks) {
            foreach ($checks as $name => $c) {
                if (!empty($c['if_set']) && !array_key_exists($field, $v)) {
                    continue;
                }
                $value = $v[$field] ?? null;
                $rule = $c['rule'];
                $ok = is_array($rule) && ($rule[0] ?? null) === 'in_array' ? in_array($value, $rule[1], true)
                    : (bool) call_user_func($rule[0], $value);
                if (!$ok) {
                    $this->errors[$field][$name] = $c['message'];
                }
            }
        }

        return !$this->errors;
    }
}

/** The handful of Record calls the gateway makes, on a JSON file (shared by the CLI test and `php -S`). */
final class HarnessRecord
{
    private static ?self $one = null;
    private string $file;
    private array $q = [];
    public static function instance(): self { return self::$one ??= new self(); }
    private function __construct() { $this->file = getenv('HARNESS_DB') ?: sys_get_temp_dir() . '/p2flux-blesta-harness.json'; }
    private function load(): array { return is_file($this->file) ? (json_decode(file_get_contents($this->file), true) ?: []) : []; }
    private function save(array $all): void { file_put_contents($this->file, json_encode($all), LOCK_EX); }
    public function setField($n, $o) { return $this; }
    public function setKey(...$a) { return $this; }
    public function create($table, $ifNotExists = false) { $all = $this->load(); $all[$table] ??= []; $this->save($all); }
    public function drop($t) { $all = $this->load(); unset($all[$t]); $this->save($all); }
    public function select() { $this->q = ['where' => [], 'order' => null]; return $this; }
    public function from($t) { $this->q['table'] = $t; return $this; }
    /** Conditions: AND between entries; an open()/close() group is OR between its own entries. */
    public function where($f, $op, $v) { $this->add([$f, $op, $v]); return $this; }
    public function orWhere($f, $op, $v) { $this->add([$f, $op, $v]); return $this; }
    public function open() { $this->q['where'][] = ['or' => []]; $this->q['group'] = true; return $this; }
    public function close() { $this->q['group'] = false; return $this; }
    private function add(array $c): void
    {
        if (!empty($this->q['group'])) {
            $this->q['where'][array_key_last($this->q['where'])]['or'][] = $c;
        } else {
            $this->q['where'][] = $c;
        }
    }
    private static function test(array $row, array $c): bool
    {
        [$f, $op, $v] = $c;
        $have = $row[$f] ?? null;
        if ($op === '>' || $op === '<') {
            return $have !== null && ($op === '>' ? $have > $v : $have < $v);
        }
        $eq = $v === null ? $have === null : (string) ($have ?? '') === (string) $v;

        return $op === '!=' ? !$eq : $eq;
    }
    public function order(array $o) { $this->q['order'] = $o; return $this; }
    private function matches(array $row): bool
    {
        foreach ($this->q['where'] ?? [] as $c) {
            $ok = isset($c['or']) ? (bool) array_filter($c['or'], static fn ($o) => self::test($row, $o)) : self::test($row, $c);
            if (!$ok) {
                return false;
            }
        }

        return true;
    }
    public function fetchAll()
    {
        $rows = array_values(array_filter($this->load()[$this->q['table']] ?? [], fn ($r) => $this->matches($r)));
        $this->q = [];

        return array_map(static fn ($r) => (object) $r, $rows);
    }
    public function fetch()
    {
        $rows = array_values(array_filter($this->load()[$this->q['table']] ?? [], fn ($r) => $this->matches($r)));
        if ($o = $this->q['order']) {
            $k = array_key_first($o);
            usort($rows, static fn ($x, $y) => $o[$k] === 'DESC' ? $y[$k] <=> $x[$k] : $x[$k] <=> $y[$k]);
        }
        $this->q = [];

        return $rows ? (object) $rows[0] : false;
    }
    public function delete()
    {
        $all = $this->load();
        $t = $this->q['table'];
        $before = count($all[$t] ?? []);
        $all[$t] = array_values(array_filter($all[$t] ?? [], fn ($r) => !$this->matches($r)));
        $this->save($all);
        $this->q = [];

        return new class ($before - count($all[$t])) { public function __construct(private int $n) {} public function rowCount(): int { return $this->n; } };
    }
    public function insert($t, array $row) { $all = $this->load(); $all[$t][] = $row; $this->save($all); }
    /** Like PDO: returns a statement whose rowCount() is the number of rows changed. Locked, so parallel
     * requests in tests behave like MySQL row locks. */
    public function update($t, array $row)
    {
        $lock = fopen($this->file . '.lock', 'c');
        flock($lock, LOCK_EX);
        $all = $this->load();
        $changed = 0;
        foreach ($all[$t] ?? [] as $i => $r) {
            if ($this->matches($r)) {
                $all[$t][$i] = array_merge($r, $row);
                $changed++;
            }
        }
        $this->save($all);
        flock($lock, LOCK_UN);
        $this->q = [];

        return new class ($changed) { public function __construct(private int $n) {} public function rowCount(): int { return $this->n; } };
    }
}

/** Invoices/Services: what the gateway reads, from HARNESS_MODELS (JSON) when set. */
final class HarnessModel
{
    public function __construct(private string $name) {}
    public function get($id)
    {
        $all = json_decode((string) getenv('HARNESS_MODELS'), false) ?: new stdClass();

        return $all->{$this->name}->{$id} ?? null;
    }
}

final class HarnessView
{
    public array $vars = [];
    public function set($k, $v) { $this->vars[$k] = $v; }
    public function fetch() { return $this->vars; }
}

abstract class NonmerchantGateway
{
    public $view;
    public $Input;
    public $Record;
    protected $currency;
    public array $logs = [];
    public function loadConfig($file) { json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); }
    public function makeView(...$a) { return new HarnessView(); }
    public function getCommonError($type) { return ['gateway' => [$type => $type]]; }
    /** Like Blesta's: Logs::addGateway refuses an empty URL (and a gateway that no longer exists), and log() throws. */
    public function log($url, $data = null, $direction = 'input', $success = false)
    {
        if ($url === null || $url === '') {
            throw new Exception('log() without a URL');
        }
        $this->logs[] = [$direction, $success, $data];
    }
}

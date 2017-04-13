<?php

class Literal {
	public $char, $escaped;
	function __construct(string $char, bool $escaped) {
		$this->char = $char;
		$this->escaped = $escaped;
	}
}
class CharClass {
	public $charset, $reversed;
	function __construct(string $charset, bool $reversed) {
		$this->charset = $charset;
		$this->reversed = $reversed;
	}
}
class LookAround {
	public $regex, $ahead, $reversed;
	function __construct($regex, bool $ahead, bool $reversed) {
		$this->regex = $regex;
		$this->ahead = $ahead;
		$this->reversed = $reversed;
	}
}
class Group {
	public $regex, $reference, $name;
	function __construct($regex, int $reference, $name) {
		$this->regex = $regex;
		$this->reference = $reference;
		$this->name = $name;
	}
}
class Repetition {
	public $base, $min, $max, $greedy;
	function __construct($base, int $min, int $max, bool $greedy) {
		$this->base = $base;
		$this->min = $min;
		$this->max = $max;
		$this->greedy = $greedy;
	}
}
class Choice {
	public $left, $right;
	function __construct($left, $right) {
		$this->left = $left;
		$this->right = $right;
	}
}
class RegexParser {
	private $str;
	private $i = 0;
	private $groupCounter = 1;
	function __construct(string $regex) {
		$this->str = $regex;
	}
	
	function peek($offset=0, $length=1): string {
		if ($length == 1)
			return $this->str[$this->i+$offset];
		return substr($this->str, $this->i+$offset, $length); // long peek
	}
	function consume(string $char): string {
		$c = $this->peek();
		if ($c == $char)
			return $this->next();
		die("expected $char, got $c");
	}
	function consumeAny(string $chars): string {
		$c = $this->peek();
		if (strpos($chars, $c))
			return $this->next();
		die("expected any of $chars, got $c");
	}
	function next(): string {
		return $this->str[$this->i++];
	}
	function more() {
		return $this->i < strlen($this->str) ? $this->i-strlen($this->str) : 0;
	}

	function regex() {
		$ret = $this->term();
		while ($this->more() and $this->peek() == '|') {
			$this->consume('|');
			$ret = new Choice($ret, $this->term());
		}
		return $ret;
	}
	function term() {
		$chain = [];
		while($this->more() and $this->peek() != ')' and $this->peek() != '|') {
			$chain[] = $this->factor();
		}
		return $chain;
	}

	function factor() {
		$base = $this->base();
		$nonrepeat = (is_a($base, 'Literal') and !$base->escaped and ($base->char == '^' or $base->char == '$')
			or is_a($base, 'LookAround'));
		if ($this->more() and !$nonrepeat) {
			$min = 0;
			$max = 0;
			$greedy = true;
			switch ($this->peek()) {
				case '*':
					$this->consume('*');
					$min = 1;
					$max = -1;
					break;
				case '+':
					$this->consume('+');
					$min = 1;
					$max = -1;
					break;
				case '?':
					$this->consume('?');
					return new Repetition($base, 0, 1, false);
				case '{': // numerated repetition
					$min_s = $this->peekNumber(1);
					if ($min_s > 0) {
						if ($this->peek(1+$min_s) == ',') {
							$max_s = $this->peekNumber(1+$min_s+1);
							if ($this->peek(1+$min_s+1+$max_s) == '}') { // {min,max} {min,}
								$this->consume('{');
								$min = $this->consumeNumber();
								$this->consume(',');
								$max = $max_s ? $this->consumeNumber() : -1;
								$this->consume('}');
								break;
							}
						}
						elseif ($this->peek(1+$min_s) == '}') { // {count}
							$this->consume('{');
							$min = $this->consumeNumber();
							$max = $min;
							$this->consume('}');
							break;
						}
					}
				default:
					return $base;
			}
			if ($this->more() and $this->peek() == '?') {
				$this->consume('?');
				$greedy = false;
			}
			return new Repetition($base, $min, $max, $greedy);
		}
		return $base;
	}

	function base() {
		switch($this->peek()) {
			case '(':
				$g = NULL;
				$reversed = false;
				$this->consume('(');
				if ($this->peek() == '?') { // special group
					$this->consume('?');
					switch($this->peek()) {
						case '<':
							$this->consume('<');
							switch($this->peek()) {
								case '!': // negative lookbehind
									$reversed = true;
								case '=': // positive lookbehind
									$this->next();
									$g = new LookAround($this->regex(), false, $reversed);
									break;
								default: // should be a named capture group
									$name = $this->consumeIdentifier();
									$this->consume('>');
									$g = new Group($this->regex(), $this->groupCounter++, $name);
							}
							break;
						case '!': // negative lookahead
							$reversed = true;
						case '=': // positive lookahead
							$this->next();
							$g = new LookAround($this->regex(), true, $reversed);
							break;
						case ':': // non capturing group
							$this->consume(':');
							$g = new Group($this->regex(), NULL, NULL);
							break;
						default:
							die("incomplete group structure at {$this->peek()}");
					}
				}
				else { // regular group
					$g = new Group($this->regex(), $this->groupCounter++, NULL);
				}
				$this->consume(')');
				return $g;
			case '[':
				$this->consume('[');
				$charset = '';
				$reversed = false;
				if ($this->peek() == '^') {
					$this->consume('^');
					$reversed = true;
				}
				while($this->peek() != ']') {
					if ($this->peek() == '\\')
						$this->consume('\\');
					$charset = $charset . $this->next();
				}
				$this->consume(']');
				return new CharClass($charset, $reversed);
			case '\\':
				$this->consume('\\');
				if (is_numeric($this->peek()))
					return ['what' => 'backreference', 'where' => $this->consumeNumber()];
				return new Literal($this->next(), true);
			default:
				return new Literal($this->next(), false);
		}
	}
	
	function peekNumber($offset=0) {
		$i = $offset;
		while ($this->peek($i) >= '0' and $this->peek($i) <= '9')
			$i++;
		return $i-$offset;
	}
	function consumeNumber() {
		if ($this->peek() < '0' or $this->peek() > '9')
			die("expected number, got {$this->peek()}");
		$num_str = $this->next();
		while($this->peek() >= '0' and $this->peek() <= '9') {
			$num_str = $num_str . $this->next();
		}
		return (int)$num_str;		
	}
	function consumeIdentifier() {
		$identifier = '';
		$peeked = $this->peek();
		while($peeked >= 'a' and $peeked <= 'z'
				or $peeked >= 'A' and $peeked <= 'Z'
				or $peeked >= '0' and $peeked <= '9'
				or $peeked == '_') {
			$identifier = $identifier . $this->next();
			$peeked = $this->peek();
		}
		if ($identifier == '') {
			die("expected identifier, got $peeked");
		}
		return $identifier;
	}
}

class ReversedRegex {
	public $tree;
	function __construct($tree) {
		$this->tree = ($tree);
	}
	
	private function _format($node, &$args, &$kwargs, &$str):int {
		if (is_array($node)) {
			$sum = 0;
			foreach ($node as $k => $v)
				$sum += $this->_format($v, $args, $kwargs, $str);
			return $sum;
		}
		elseif (is_a($node, 'Literal')) {
			if ($node->escaped and strpos('wWdDsS', $node->char) !== false or !$node->escaped and $node->char == '.') {
				$str .= array_pop($args);
				return 1;
			}
			if ($node->escaped or $node->char != '$' and $node->char != '^')
				$str .= $node->char;
			return 0;
		}
		elseif (is_a($node, 'Choice')) {
			die ('Unsupported operation');
		}
		elseif(is_a($node, 'Group')) {
			$test_args = [];
			$test_str = '';
			$count = @$this->_format($node->regex, $test_args, $test_args, $test_str);
			if ($count > 0) {
				$str .= $kwargs[$node->name] ?? array_pop($args);
				return 1;
			}
			$str .= $test_str;
			return 0;
		}
		elseif(is_a($node, 'LookAround')) {
			if ($node->reversed) {
				$str .= array_pop($args);
				return 1;
			}
			return $this->_format($node->regex, $args, $kwargs, $str);
		}
		elseif(is_a($node, 'CharClass')) {
			$str .= array_pop($args);
			return 1;
		}
		elseif(is_a($node, 'Repetition')) {
			if ($node->min != $node->max) { //variable length repetition
				$str .= array_pop($args);
				return 1;
			}
			$test_args = [];
			$test_str = '';
			$count = @$this->_format($node->regex, $test_args, $test_args, $test_str);
			if ($count == 0) { // fixed-length repetition and literal base
				$str .= str_repeat($test_str, $this->min);
				return 0;
			}
			$str .= array_pop($args);
			return 1;
		}
		return 0;
	}
	
	function format($args) {
		$str = '';
		$pos_args = array_reverse(array_filter($args, 'is_int', ARRAY_FILTER_USE_KEY));
		$kw_args = array_diff_key($args, $pos_args);
		$this->_format($this->tree, $pos_args, $kw_args, $str);
		return $str;
	}
}



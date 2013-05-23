<?php
namespace PHParse;

/*

Top Down Operator Precendence Parser in PHP

The code is documented using Plain Old Documentation, so the
documentation can be extracted with any of the pod2* commands,
e.g. on Linux:

    $ pod2text parser.php | less

or

    $ pod2man parser.php | man -l -

The license statement is found at the end of the file. It is part of the
automatically extracted documentation and thus fits better at the end of
the end.

The parser is simple and doesn't do a lot of error checking, nor does it
remember where a token occurred in the input text.

Things that should/could be added to make something useful out of this:

- store line and column information together with tokens,

- provide hooks for error handling,

- separate lexer and parser,

- use arrays of anonymous functions for token "classes"; that would make
  dynamic token "class" generation possible without resorting to "eval";

- add a test suite,

- hard code a EBNF parser and use that parser for generating new lexers
  and parsers from grammars in EBNF,

- create a language that compiles transparently to PHP in order to avoid
  common pitfalls in PHP (e.g. translate "==" to "===") and facilitate
  meta-programming.
  
=head1 DESCRIPTION    
 
This is an implementation of a top-down operator precedence parser,
inspired by Douglas Crockford[1].

The parser is implemented using statically created classes (as opposed
to Crockford's implementation using JavaScript objects) for Tokens in
the parsed language, since I am not aware of any good way of creating
classes in PHP dynamically.

[1] http://javascript.crockford.com/tdop/tdop.html

=head1 USAGE

    php parser.php [parse|eval|compile] code
    php parser.php help

=head1 EXAMPLES

    $ php parser.php parse '1 + 2'
    (+ 1 2)
    
    $ php parser.php eval 'echo(1 + 2)'
    3
    
    $ php parser.php compile 'a = 1; b = 2'
    $a = 1;
    $b = 2;
    
    $ php parser.php help
    # print POD as text on STDOUT
    
=head1 DATA MODEL

=head2 Rule

A rule represents an instruction for the lexer.  Rules can C<accept>
input strings and produce a C<token> if an input has been accepted.  The
accepted inputs for a rule are defined by the regular expression
C<$match>, which should include exactly one capturing group.  The value
captured by this group is used as the value for the token.  If nothing
is captured, the value will be C<'(empty)'>.

Examples:

    // a rule matching numbers
    $number = new Rule('/\s*(\d+)/', 'PHParse\NumberToken');
    // a rule matching the + operator
    $number = new Rule('/\s*(\+)/', 'PHParse\OpPlusToken');

=cut

*/

class Rule {
   public $match;               /* regular expression matching a token */
   public $toktype;             /* fully qualified name of the token class */
   
   public function __construct($match,$toktype) {
      $this->init($match,$toktype);
   }
   
   public function init($match,$toktype) {
      $this->match = $match;
      $this->toktype = $toktype;
      
      return $this;
   }

/*

=head3 C<accept(&$string,&$result)>

Try matching the rule at the beginning of C<$string> and remove the
matched text from C<$string>.  A token is created from the matched
string and stored in C<$result>.

Returns the length of the match or -1 if the input string was rejected.

=cut

*/

   public function accept(&$string,&$result) {
      $matches = [];

      if (preg_match($this->match,$string,$matches) === 1) {
         if (count($matches) < 2) {
            $matches[] = '(empty)';
         }
         $result = new $this->toktype($matches[1]);
         return strlen($matches[0]);
      } else {
         return -1;
      }
   }
}

/*

=head2 Token

Tokens define how the parse tree is constructed.  The class C<Token> is
intended to be subclassed in order to implement concrete token types.  A
Token-class should implement either C<nud>, C<led> or both.  The method
C<nud> ("null denotation") is used for prefix operators, identifiers and
statements (i.e. when the token occurs in prefix position).  Similarily,
C<led> ("left denotation") deals with tokens occurring in infix
positions.  The property C<$lbp> ("left-binding power") determines how
tightly the token binds to the left, i.e. whether

    a OP1 b OP2 c

should be interpreted as

    (a OP1 b) OP2 c     // high lbp

or

    a OP1 (b OP2 c)     // low lbp

A C<$lbp> of 0 means the token doesn't bind at all (e.g. statement
separators).
    
=cut

*/

class Token {
   public $lbp = 0;             /* left-binding power */
   private $name = '(token)';   /* name of the token type */
   public $value = '';          /* the value of the token */

   public function __construct($value) {
      $this->init($value);
   }

   public function init($value) {
      $this->value = $value;
   }
   
   /* handle call in prefix position */
   public function nud(Parser $parser) {
      error_log("Syntax error: ".$this->name);
      return null;
   }

   /* handle call in infix position */
   public function led(Parser $parser, $left) {
      error_log("Syntax error: ".$this->name);
      return null;
   }
}


/*

=head2 EndToken

This class is used for the token signalling the end of the input.
Tokens of this type don't bind anything, hence the C<$lbp> of 0.

=cut

*/

class EndToken extends Token {
   public $lbp = 0;
   public $name = '(end)';
}

/*

=head2 Parser

This class deals with lexing and parsing of a given input string.  Input
is first tokenized and then parsed according to rules added to the
parser.  The order in which rules are added to the parser is important,
as it is also the order in which the rules are matched against the input.

Example:

    $parser = new Parser();
    $parser->add_rule(new Rule('/^if/','IfToken'));
    $parser->add_rule(new Rule('/^([a-zA-Z0-9_]+)/','IdentifierToken'));
    $parser->parse('if a');

If the rules in the example were added in reversed order, the rule for
C<'IfToken'> would never apply, since the rule for C<'IdentifierToken'>
also accepts the string C<'if'>.

=cut

*/

class Parser {
   private $token = null; /* the current token */
   private $rules = [];   /* ordered list of registered rules  */
   private $input = [];   /* list of input tokens */


/*

=head3 C<parse($input='')>

Parse C<$input> and return the resulting abstract syntax tree (AST).
Issues a if parts of C<$input> cannot be tokenized by this parser.  The
structure of the AST depends on the implementation of the Token classes
referenced by the parser's rules.

=cut

*/
   public function parse($input='') {
      $this->input = $this->tokenize($input);
      $this->token = $this->next();
      return $this->expr();
   }

/*

=head3 C<expr($rbp=0)>

Parse and return an expression.  The value of C<$rbp> determines how
much the expression binds to the right.  Use this function in C<nud> and
C<led> implementations of Token-subclasses.

=cut

*/
   
   public function expr($rbp=0) {
      $t = $this->token;
      $this->token = $this->next();
      $left = $t->nud($this);
      
      while ($this->token && $rbp < $this->token->lbp) {
         $t = $this->token;
         $this->token = $this->next();
         $left = $t->led($this,$left);
      }

      return $left;
   }

   private function next() {
      if (count($this->input) == 0) {
         return new EndToken('end');
      } else {
         return array_shift($this->input);
      }
   }

/*

=head3 C<tokenize(&$input)>

Split C<$input> into tokens and return the resulting list.  After
tokenization C<$input> is empty or contains text that could not be
tokenized.

=cut

*/
   
   public function tokenize(&$input) {
      $match = ''; $token = null; $result = [];

      while (strlen($input) > 0) {
         $previnput = $input;
         foreach ($this->rules as $rule) {
            $len = $rule->accept($input,$token);
            if ($len !== -1) {
               $input = substr($input,$len);
               $result[] = $token;
               break;
            }
         }
         if ($input === $previnput) {
            error_log("unable to tokenize \"$input\"");
            break;
         }
      }

      $result[] = new EndToken('end');

      return $result;
   }

/*

=head3 C<add_rule(Rule $rule)>

Append C<$rule> to the list of this parser's rules.

=cut
   
*/
   public function add_rule(Rule $rule) {
      $this->rules[] = $rule;

      return $this;
   }
}

/*

=head1 EXAMPLE PARSER

A simple parser for arithmetic infix expressions is defined as an
example.  The parser supports prefix C<+> and C<->, variable assignment
with C<=>, and the four basic arithmetic operations (addition,
subtraction, multplication, division).  Expressions can be concatenated
with C<;>. Parentheses can be used for grouping expressions.

Example expressions:

    a = 1                  // 1
    a = 10; b = 20; a * b  // 30
    10 * 2 + 1             // 21
    10 * (2 + 1)           // 30
    -10 + 10               // 0

=cut

*/

class NumberToken extends Token {
   public $name = 'number';
   public $lbp = 0;
   
   public function nud(Parser $parser) {
      return $this->value;
   }
}

class OpPlusToken extends Token {
   public $name = '+';
   public $lbp = 50;

   public function nud(Parser $parser) {
      return $parser->expr(70);
   }

   public function led(Parser $parser, $left) {
      $right = $parser->expr($this->lbp);
      return ['+', $left, $right];
   }
}

class OpMinusToken extends Token {
   public $name = '-';
   public $lbp = 50;

   public function nud(Parser $parser) {
      return -$parser->expr(70);
   }

   public function led(Parser $parser, $left) {
      $right = $parser->expr($this->lbp);
      return ['-', $left, $right];
   }
}

class OpMulToken extends Token {
   public $name = '*';
   public $lbp = 60;

   public function led(Parser $parser, $left) {
      $right = $parser->expr($this->lbp);
      return ['*', $left, $right ];
   }
}

class OpDivToken extends Token {
   public $name = '/';
   public $lbp = 60;

   public function led(Parser $parser, $left) {
      $right = $parser->expr($this->lbp);
      return ['/', $left, $right];
   }
}

class SymbolToken extends Token {
   public $name = 'symbol';
   public $lbp = 0;

   public function nud(Parser $parser) {
      return $this->value;
   }
}

class AssignmentToken extends Token {
   public $name = 'setq';
   public $lbp = 20;

   public function led(Parser $parser, $left) {
      $right = $parser->expr($this->lbp);
      return ['setq', $left, $right];
   }
}

class DisjunctionToken extends Token {
   public $name = 'disjunction';
   public $lbp = 10;

   public function led(Parser $parser, $left) {
      $right = $parser->expr($this->lbp);

      if (is_array($left) && $left[0] === 'progn') {
         $result = $left;
         $result[] = $right;
   } else {
         $result = ['progn',$left];
         if ($right !== null) { $result[] = $right; }
      }
      
      return $result;
   }
}

class SubExpToken extends Token {
   public $name = 'subexpression';
   public $lbp = 80;

   public function nud(Parser $parser) {
      $tok = $parser->expr(0);
      $rparen = $parser->expr($this->lbp);
      return $tok;
   }

   public function led(Parser $parser,$left) {
      $right = $parser->expr(0);
      $rparen = $parser->expr($this->lbp);
      return [$left,$right];
   }
}

class SeparatorToken extends Token {
   public $name = 'separator';
   public $lbp = 0;

   public function nud(Parser $parser) {
   }
}

/*

=head1 UTILITY FUNCTIONS

The following functions are provided to show what can be done with the
parser.  Since the parser returns simple nested arrays, these functions
are not strictly necessary to make sense out of the parsed data.  They do illustrate 

=head2 C<sexp_to_string($arg)>

Convert a plain PHP array into a symbolic expression (S-Expression or
sexp).  Symbolic expressions express hierarchically structured data
(i.e. trees) and are common way of storing LISP source code.

Examples:

    sexp_to_string(['+',1,2]);          // '(+ 1 2)'
    sexp_to_string(['*',['+',2,3],12]); // '(* (+ 2 3) 12)'

The result of this function can be passed to a lisp interpreter that
knows the functions 'setq' and 'progn' (e.g. Emacs):

    $ emacs --batch --eval "(message \"%s\" $(php parser.php parse '1 + 2'))"
    3

=cut

*/

function sexp_to_string($arg) {
   if (is_array($arg)) {
      return '(' . join(' ',array_map(function ($e) {
               return sexp_to_string($e);
            },$arg)) . ')';
   } else {
      return $arg;
   }
}

/*

=head2 C<sexp_to_php($arg,$first=false)>

Convert a plain PHP array into PHP code.  The array is expected to
describe code with prefix notation, i.e. the first element in the array
denotes the operator, the remaining elements denote the arguments.

C<$arg> can be an array or scalar value.  C<$first> should be set to
true, if C<$arg> is a scalar value denoting an operator (i.e. the
B<first> element in an array).

Examples:

    sexp_to_php(['echo',['+',1,2]]); // echo(1 + 2)
    sexp_to_php(['*','length',2]]);  // $length * 2
    sexp_to_php('echo',$first=true); // echo
    sexp_to_php('echo');             // $echo

=cut

*/

function sexp_to_php(&$arg,$first=false) {
   if (!is_array($arg)) {
      /* If the element is in operator position (i.e. the first element
       * in the array), we just return it as is, assuming it is a valid
       * PHP identifier. */
      if ($first) { return $arg; }

      /* numbers must not be prefixed with a dollar sign */
      if (preg_match('/^-?\d+$/',$arg)) {
         return $arg;
      }

      /* If we get here, we are probably looking at a variable. */
      return '$'.preg_replace('/-/','_',$arg);
   }

   /* If there any more primitives are added, a dispatch table should be
    * used instead of chained if-statements.  Using anonymous functions
    * in PHP is cumbersome, so if-statements seemed like a cleaner
    * solution in this case. */
   
   /* progn
    *
    * (progn (setq minute 60) echo(* 3 minute)) =>
    *     $minute = 60;
    *     echo(3 * $minute);*/
   if ($arg[0] === 'progn') {
      return join(";\n", array_map(function ($e) {
               return sexp_to_php($e);
            }, array_slice($arg,1))) . ';';
   }

   /* setq
    *
    * (setq a 1) =>
    *     $a = 1
    */
   if ($arg[0] === 'setq') {
      return sexp_to_php($arg[1]) . ' = ' . sexp_to_php($arg[2]);
   }

   /* function call
    *
    * (echo (+ 2 3)) =>
    *     echo(2 + 3)
    */
   if (!is_array($arg[0]) && count($arg) === 2 && is_array($arg[1])) {
      return sexp_to_php($arg[0],true) . '(' . sexp_to_php($arg[1]) . ')';
   }
   
   /* prefix -> infix
    *
    * (+ 2 3) =>
    *     2 + 3
    */
   return sexp_to_php($arg[1]) . ' ' . sexp_to_php($arg[0],true) . ' ' . sexp_to_php($arg[2]);
}

function do_parse($parser, $input) {
   echo sexp_to_string($parser->parse($input)),"\n";
}

function do_eval($parser, $input) {
   eval(sexp_to_php($parser->parse($input)));
}

function do_compile($parser, $input) {
   echo sexp_to_php($parser->parse($input)),"\n";
}

function do_help($parser, $input) {
   global $argv;

   if (file_exists($argv[0])) {
      system("pod2text ".escapeshellarg($argv[0]));
   } else {
      error_log("No such file: \"$file\"");
   }
}

function do_test($parser,$input) {
   require_once('tap.php');

   $t = new \TAP();

   $t->diag('symbolic expressions');
   $t->is(sexp_to_string($parser->parse('1 + 2')),'(+ 1 2)', 'simple expression');
   $t->is(sexp_to_string($parser->parse('1 + 2 * 3')),'(+ 1 (* 2 3))','precendence');
   $t->is(sexp_to_string($parser->parse('a = 1')), '(setq a 1)','assignment');
   $t->is(sexp_to_string($parser->parse('1 + 2; 3 + 4')),
          '(progn (+ 1 2) (+ 3 4))','progn');
   $t->is(sexp_to_string($parser->parse('(1 + 2) * 3')),
          '(* (+ 1 2) 3)', 'parenthesized subexpressions');

   $t->diag('php code');
   $t->is(sexp_to_php($parser->parse('echo(1 + 2)')), 'echo(1 + 2);','function call');

   $t->done();
}

function main($argv) {
   $parser = new Parser();
   $parser->add_rule(new Rule('/^\s*(\d+)\s*/','PHParse\NumberToken'));
   $parser->add_rule(new Rule('/^\s*([+])\s*/','PHParse\OpPlusToken'));
   $parser->add_rule(new Rule('/^\s*(-)\s*/','PHParse\OpMinusToken'));
   $parser->add_rule(new Rule('/^\s*(\*)\s*/','PHParse\OpMulToken'));
   $parser->add_rule(new Rule('/^\s*(\/)\s*/','PHParse\OpDivToken'));
   $parser->add_rule(new Rule('/^\s*(\()\s*/', 'PHParse\SubExpToken'));
   $parser->add_rule(new Rule('/^\s*(\))\s*/', 'PHParse\SeparatorToken'));
   $parser->add_rule(new Rule('/^\s*(=)\s*/','PHParse\AssignmentToken'));
   $parser->add_rule(new Rule('/^\s*(;)\s*/','PHParse\DisjunctionToken'));
   $parser->add_rule(new Rule('/^\s*([a-zA-Z0-9_]+)\s*/','PHParse\SymbolToken'));

   if (count($argv) == 1) {
      $argv[] = 'test';
   }

   if (count($argv) == 2) {
      $argv[] = '';
   }

   $action = 'PHParse\do_' . $argv[1];
   if (function_exists($action)) {
      call_user_func($action,$parser,$argv[2]);
   } else {
      error_log('No such action: "' . $argv[1] . '"');
   }
}

main($argv);

/*
                                                               
=head1 LICENSE

Top-down operator precedence parser implementation
Copyright (C) 2013  Dario Hamidi

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see L<http://www.gnu.org/licenses/>.

=head1 AUTHOR

Dario Hamidi, C<dario.hamidi@gmail.com>, L<https://github.com/dhamidi>

=cut

*/    

?>
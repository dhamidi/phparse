<?php

/*
 * TAP support for PHP
 *
 * This module is a port of a subset of Perl's Test::More to PHP. It
 * emits TAP (Test Anything Protocol) compliant output.  This means that
 * you can use "prove" (should be available everywhere, where perl is
 * found) to automatically run your tests and check the test results.
 *
 */

class TAP {
   private static $out = null;
   private $count;
   private $failed;

   public function __construct() {
      if (self::$out === null) {
         self::$out = fopen('php://stderr','w');
      }

      $this->init();
   }
   
   public function init() {
      $this->count  = 0;
      $this->failed = 0;
   }

   function ok($val,$test_name=null) {
      $this->count++;
   
      if (!$val) {
         $this->failed++;
      }
   
      fprintf(self::$out,"%sok %d%s\n",
              ($val ? '' : 'not '),
              $this->count,
              ($test_name !== null ? ' - ' . $test_name : ''));

      return !!$val;
   }

   function diag($msg) {
   
      $lines = join("\n",
                    array_map(function ($line) {
                          return '# ' . $line;
                       }, split("\n", $msg)));
   
      fprintf(self::$out,"# %s\n", $msg);
   }

   function is($got,$expected,$test_name=null) {
      if ($got !== $expected) {
         fprintf(self::$out,"# %10s: '%s'\n# %10s: '%s'\n",
                 'got', $got, 'expected', $expected);
      }
      $this->ok($got === $expected,$test_name);
   }

   function done() {
      fprintf(self::$out,"1..%d\n", $this->count);
   }


}

?>
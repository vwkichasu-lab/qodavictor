<?php
echo "<pre>";
echo "=== Checking Compilers ===\n\n";

// Check Java
echo "Java: ";
exec("javac -version 2>&1", $out, $code);
echo $code === 0 ? "✓ INSTALLED\n" : "✗ NOT INSTALLED\n";

// Check Python
echo "Python: ";
exec("python3 --version 2>&1", $out, $code);
echo $code === 0 ? "✓ INSTALLED\n" : "✗ NOT INSTALLED\n";

// Check Node.js
echo "Node.js: ";
exec("node --version 2>&1", $out, $code);
echo $code === 0 ? "✓ INSTALLED\n" : "✗ NOT INSTALLED\n";

// Check GCC (C/C++)
echo "GCC: ";
exec("gcc --version 2>&1", $out, $code);
echo $code === 0 ? "✓ INSTALLED\n" : "✗ NOT INSTALLED\n";

echo "\n</pre>";
?>
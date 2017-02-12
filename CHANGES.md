# Locomotive Change Log

<a name="1.0.3"></a>
## 1.0.3
- Addresses [PHP Net Bug #73561](https://bugs.php.net/bug.php?id=73561) introduced in PHP version 5.6.28 and 7.0.13 by excplictily casting sFTP over SSH2-wrapped stream resources to integers
- Updates README to reflect version requirements for libssh2

<a name="1.0.2"></a>
## 1.0.2
- Bugfix: `LocalQueue::pluck()` was returning a string instead of a `Collection` when only one item exited in the local queue

<a name="1.0.1"></a>
## 1.0.1
- Updates to support Illuminate 5.2 deprecations

<a name="1.0.0"></a>
## 1.0.0
- Initial release
- Adds some basic info to the README file
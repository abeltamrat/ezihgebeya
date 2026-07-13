<?php
// Replaced by the React authenticated vendor workflow. Keep this route as a
// compatibility redirect for old bookmarks, admin notifications, and stale tabs.
require_vendor();
redirect('app/vendor/business');

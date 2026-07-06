<?php
require __DIR__ . "/../config.php"; require __DIR__ . "/../app/db.php";
$icons = ["sofa"=>"🛋️","bed"=>"🛏️","dining-table"=>"🍽️","chair"=>"🪑","office-furniture"=>"🗄️","kitchen-cabinet"=>"🚪","wardrobe"=>"🚪","tv-stand"=>"📺","door"=>"🚪","wall-panel"=>"🧱","decor"=>"🖼️","lighting"=>"💡","curtain"=>"🪟","interior-design"=>"🎨","gypsum-work"=>"🏗️","painting"=>"🖌️","electrical-work"=>"⚡","plumbing"=>"🔧","flooring"=>"🪵","kitchen-cabinet-installation"=>"🔨","furniture-installation"=>"🛠️","renovation"=>"🏠","wood-work"=>"🪚","metal-work"=>"⚙️","aluminum-work"=>"🪟","glass-work"=>"🔷","mdf-board"=>"📦","plywood"=>"🪵","solid-wood"=>"🌳","veneer"=>"📄","hardware-accessories"=>"🔩","paint-finishing"=>"🎨","foam"=>"🧽","fabric-leather"=>"🧵","tools"=>"🧰","machinery"=>"🏭"];
foreach ($icons as $slug => $icon) q("UPDATE categories SET icon = ? WHERE slug = ?", [$icon, $slug]);
echo "icons fixed: " . count($icons) . "\n";

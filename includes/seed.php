<?php
/**
 * Starting data copied from the reference site https://vermionline.in (read on 29 Oct 2026):
 * categories, all 22 products with their IDs, product codes, sizes, prices, descriptions, kit details and image file names,
 * the store-wide offer, loose compost rates, contact details and page content. install.php loads it into an empty database.
 * Product images are copied from the reference site in Admin > Tools.
 */

function seed_settings(): array
{
    return [
        'store_name' => 'Vermi Compost',
        'legal_name' => 'Pankaj Dheeraj Vermi Compost Pvt. Ltd.',
        'tagline' => 'Earthworm-made compost for pots, terraces, nurseries and farms, in bags or loose by the kilo.',
        'site_domain' => 'vermionline.in',
        'store_email' => 'info@vermionline.in',
        'mail_from' => 'info@vermionline.in',
        'store_phone' => '+91 6284637649',
        'whatsapp' => '916284637649',
        'address' => 'SCO 32, 1st Floor, New Sunny Enclave, Sector 125, SAS Nagar, Mohali, Punjab 140301',
        'hours' => 'Monday to Saturday, 10:00 AM to 6:00 PM',
        'free_shipping_above' => '500',
        'shipping_fee' => '50',
        'dispatch_time' => '7-8 days',
        'offer_enabled' => '1',
        'offer_name' => 'Diwali',
        'offer_title' => 'Get 30% off this Diwali',
        'offer_text' => 'On every product, including loose compost by the kilo.',
        'offer_percent' => '30',
        'offer_includes_loose' => '1',
        'loose' => json_encode(['enabled' => true, 'min' => 10, 'max' => 500, 'step' => 5, 'slabs' => [[10, 14], [100, 12], [500, 10], [1000, 8]]]),
        'payu_mode' => 'test',
        'payu_key' => '',
        'payu_salt' => '',
        'order_prefix' => 'ORD',
        'order_emails' => '1',
        'import_window_minutes' => '60',
        'import_max_rows' => '20000',
        'reference_url' => 'https://vermionline.in',
    ];
}

function seed_categories(): array
{
    // id, name, slug, sort order
    return [[1, 'Vermicompost', 'vermicompost', 0], [2, 'Garden inputs', 'garden-inputs', 0]];
}

/** id, category, name, size, price before the offer, image files, description, kit details. Product code = VC-{id}, as on the live site. */
function seed_products(): array
{
    $vc = 'Vermicompost is a value for money organic fertilizer for your pots and plants. It delivers required nutrients, essential micronutrients, natural plant growth';
    $kit = function ($composition, $idealFor) {
        return [['Kit Composition', $composition], ['Ideal For', $idealFor], ['Organic Status', '100% Organic']];
    };
    return [
        [2, 1, 'Vermicompost 5kg - Organic Fertilizer / Manure', '5 kg', 120.00, ['696892dcaafa4_b.jpg', '696892dca9641_b.jpg', '696892dcab5ce_b.jpg'],
            $vc . ' hormones, active fungi and bacteria, nutrient unlocking enzymes, and the best soil humus to boost your most valuable asset. It will take the overall performance of your soil to its true potential.', []],
        [3, 1, 'Vermicompost 10kg - Organic Fertilizer / Manure', '10 kg', 200.00, ['6968981128cfe_b.jpg', '69689811280d9_b.jpg'], $vc, []],
        [4, 1, 'Vermicompost 15kg - Organic Fertilizer / Manure', '15 kg', 280.00, ['696899300bb25_b.jpg', '696899300ac19_b.jpg'], $vc, []],
        [22, 2, 'Cow Dung Manure', '10 kg', 399.00, ['69ef31f90e799_b.png'], 'Natural cow dung manure for improving soil texture and plant nutrition.', []],
        [23, 2, 'Neem Cake Powder', '5 kg', 699.00, ['69ef32106b943_b.png'], 'Organic neem cake powder used as fertilizer and natural pest control.', []],
        [24, 2, 'Potting Mix Premium', '20 kg', 899.00, ['69ef321ee5bfb_b.png'], 'Ready-to-use premium potting mix for indoor and outdoor plants.', []],
        [25, 2, 'Liquid Bio Fertilizer', '1 Litre', 1099.00, ['69ef322af2320_b.png'], 'Eco-friendly liquid fertilizer for faster nutrient absorption and greener plants.', []],
        [26, 2, 'Terrace Gardening Kit', 'Standard Kit', 1499.00, ['69ef323a90123_b.png'], 'Complete terrace gardening kit with soil, tools, pots, and seeds for home gardening.', []],
        [28, 2, 'Vermicompost 8kg - Organic Fertilizer / Manure', '8kg', 142.86, ['6ad5c55ee3b1b_b.png'],
            '100% natural, earthworm-processed organic compost rich in Nitrogen, Phosphorus, and Potassium (NPK). Enhances soil aeration, improves water retention, and boosts healthy plant growth for all vegetables, flowers, and indoor garden plants.', []],
        [29, 2, 'Fully Decomposed Cow Dung Manure / Gobar Manure', '8kg', 142.86, ['6ad5c5b8a47fb_b.png'],
            'Aged, odorless, and fully fermented traditional cow dung manure. Ideal for conditioning soil texture, providing slow-release nutrition, and reviving soil fertility for home gardens, lawns, and potted plants.', []],
        [30, 2, 'Ready-to-Use Organic Potting Soil Mix', '8kg', 142.86, ['6ad5c60965996_b.png'],
            'A balanced, lightweight blend of organic compost, cocopeat, and essential nutrients. Keeps soil aerated, prevents root rot, and provides immediate plant nutrition right out of the bag without requiring extra fertilizer.', []],
        [31, 2, 'Bio-Organic Plant Food & Soil Booster', '8kg', 142.86, ['6ad5c6bff2617_b.png'],
            'Lab-tested, nutrient-rich organic compost formulation designed for fruit and flower plants. Enhances microbial activity in soil, improves root development, and protects plants against seasonal stress.', []],
        [32, 2, 'Premium Bulk Gardening & Farming Kit', '1 Kit', 71428.57, ['6ae08ba7eacff_b.png'],
            'A comprehensive organic gardening and farming kit featuring vermicompost, cow dung manure, premium potting mix, and neem cake powder for soil enrichment and plant care.',
            $kit('Vermicompost 15kg (100 bags), Vermicompost 10kg (100 bags), Vermicompost 5kg (100 bags), Cow Dung Manure 10kg (50 bags), Potting Mix Premium 20kg (25 bags), Neem Cake Powder 5kg (20 bags), Heavy-Duty Hand Shovels/Spades (5 units), Garden Pruning Shears (2',
                'Large-scale farming, commercial growers, and large gardens')],
        [33, 2, 'Organic Gardening Kit', '1 Kit', 14285.71, ['6ae08dc197af5_b.png'],
            'An organic gardening bundle containing vermicompost, cow dung manure, neem cake powder, and liquid bio fertilizer to support soil health and everyday plant care.',
            $kit('Vermicompost 15kg (10 bags), Vermicompost 10kg (10 bags), Vermicompost 5kg (10 bags), Cow Dung Manure 10kg (5 bags), Neem Cake Powder 5kg (5 bags), Liquid Bio Fertilizer 1L (2 bottles), Hand Trowel & Hand Rake Set (1 set), Spray Bottle 1L (1 unit), Garden',
                'Home gardens, balcony gardens, and everyday plant care')],
        [34, 2, 'Complete Kitchen Garden Starter Kit', '1 Kit', 2857.14, ['6ae1d4b37fbe5_b.png'],
            'A beginner-friendly organic kit designed for growing fresh vegetables at home. Includes grow bags, rich soil amendments, organic pest protection, vegetable seeds, and basic hand tools.',
            $kit('8–10 Grow Bags, Cocopeat Block, Vermicompost, Vegetable Seeds Packets, Neem Cake Powder, Hand Trowel & Weeder Tool Set', 'Home growing, balcony gardens, and kitchen garden beginners')],
        [35, 2, 'Premium Balcony Gardening Kit', '1 Kit', 2857.14, ['6ae1d56c90594_b.png'],
            'A compact organic gardening kit specially designed for urban balconies and small spaces. Includes grow bags, enriched potting mix, vermicompost, liquid bio-fertilizer, seed packets, and a spray bottle for easy home plant care.',
            $kit('6–8 Grow Bags, Premium Potting Mix, Vermicompost, Liquid Bio-Fertilizer (1L), Vegetable/Flower Seeds Packets, Spray Bottle (1L) Ideal For', 'Balcony gardens, small urban spaces, and terrace plants')],
        [36, 2, 'Organic Soil Enrichment Combo', '1 Kit', 2857.14, ['6ae1d606147fc_b.png'],
            'A high-nutrition soil replenishment bundle designed for existing home gardens and potted plants. Includes a balanced mix of premium vermicompost, cow dung manure, potting mix, and neem cake powder for natural soil conditioning and plant health.',
            $kit('Vermicompost (5kg), Cow Dung Manure (5kg), Premium Potting Mix (5kg), Neem Cake Powder (2kg)', 'Soil enrichment, repotting, and regular plant nutrition')],
        [37, 2, 'Complete Terrace Farming Ki', '1 Kit', 4285.71, ['6ae1d69132b8f_b.png'],
            'A comprehensive high-yield gardening setup designed for cultivating a productive terrace vegetable garden. Includes grow bags, cocopeat, vermicompost, potting mix, high-germination vegetable seeds, protective gardening gloves, and essential hand tools.',
            $kit('12–15 Grow Bags, Cocopeat Block, Vermicompost, Premium Potting Mix, Vegetable Seeds Packets, Heavy-Duty Garden Gloves (1 pair), Hand Trowel & Weeder Tool Set', 'Terrace farming, rooftop gardens, and high-yield vegetable growing')],
        [38, 2, 'Premium Home Farming Kit', '1 Kit', 7142.86, ['6ae1d7934658f_b.png'],
            'A complete, high-capacity farming solution for families growing a wide variety of organic vegetables at home. Includes grow bags, rich growing media, organic vermicompost, cow dung manure, seed packs, liquid plant nutrition, a watering can, and essential hand tools.',
            $kit('15–20 Grow Bags, Premium Growing Media / Cocopeat, Vermicompost, Cow Dung Manure, Vegetable Seeds Packets, Liquid Plant Nutrition (1L), Watering Can (5L), Hand Trowel & Garden Gloves Set', 'Family home gardens, balcony vegetable setups, and terrace farming')],
        [39, 2, 'Small-Scale Organic Farming Kit', '1 Kit', 7142.86, ['6ae1d828a5a61_b.png'],
            'A comprehensive organic starter bundle designed for kitchen farms, small agricultural plots, and hobby farmers. Combines bulk organic manure, nutrient-rich vermicompost, soil mix, neem cake powder, seed varieties, and liquid bio-fertilizer for sustainable crop growth.',
            $kit('Bulk Cow Dung Manure (10kg), Vermicompost (15kg), Soil Mix / Potting Mix (10kg), Neem Cake Powder (5kg), Liquid Bio-Fertilizer (1L), Selected Crop/Vegetable Seed Packets', 'Small farm plots, kitchen farms, and hobby farmers')],
        [40, 2, 'Organic Plant Growth & Soil Care Combo', '1 Kit', 4285.71, ['6ae1d88563733_b.png'],
            'A premium plant-care and soil-conditioning bundle tailored for established home gardens. Formulated to enhance soil vitality, boost plant immunity, and promote root and foliage growth using organic amendments and natural bio-stimulants.',
            $kit('Vermicompost (10kg), Neem Cake Powder (3kg), Liquid Bio-Fertilizer (1L), Seaweed Extract Fertilizer (500ml), Humic Acid Soil Conditioner (500ml)', 'Established home gardens, flowering plants, and soil vitality recovery')],
        [41, 2, 'Nursery & Landscaping Professional Kit', '1 Kit', 7142.86, ['6ae1d90f4a31b_b.png'],
            'A high-volume organic input kit tailored for nursery operators and landscaping professionals. Contains bulk supplies of vermicompost, potting mix, cow dung manure, neem cake powder, and liquid bio-fertilizer for nursery plant production and commercial landscape maintenance.',
            $kit('Vermicompost 15kg (2 bags), Cow Dung Manure 10kg (2 bags), Potting Mix Premium 20kg (1 bag), Neem Cake Powder 5kg (1 bag), Liquid Bio Fertilizer 1L (2 bottles)', 'Commercial nurseries, landscaping projects, professional growers, and large gardens')],
    ];
}

function seed_pages(): array
{
    return [
        'about_us' => ['About us', 'Earthworm-made compost for pots, terraces, nurseries and farms.',
            '<p>{store_name} is a brand of {legal_name}, based in Mohali, Punjab. We make and sell vermicompost: the dark, crumbly castings that earthworms leave behind after they digest organic waste. It improves soil texture, holds water and feeds plants slowly.</p>'
            . '<h2>How our compost is made</h2><ol><li><strong>Collection.</strong> Kitchen scraps, crop residues and green waste are gathered and sorted.</li><li><strong>Composting.</strong> The material goes into beds stocked with red wiggler earthworms.</li><li><strong>Maturing.</strong> Over about 60 to 90 days the worms turn it into fine castings full of beneficial microbes.</li><li><strong>Sieving and packing.</strong> Each batch is sieved and checked before it is packed or sold loose.</li></ol>'
            . '<h2>What we sell</h2><ul><li>Vermicompost in bags for home gardens and terraces.</li><li>Loose vermicompost by the kilo, from {loose_min} kg upwards, for farms, nurseries and landscapers. Use the <a href="{link:#loose}">price calculator</a> to see the cost.</li><li>Other organic inputs such as cow dung manure, neem cake, potting mix and garden kits.</li></ul>'
            . '<h2>How ordering works</h2><p>Order online and pay securely by UPI, card or net banking through PayU. Orders are usually dispatched within {dispatch_time} and delivered across India. See our <a href="{link:shipping-policy}">shipping policy</a> for charges and timelines.</p>{contact_block}'],
        'faq' => ['Frequently asked questions', 'Quick answers about our compost, ordering and delivery.',
            '<details open><summary>What is vermicompost?</summary><p>Compost made by earthworms. They eat organic waste and leave behind castings rich in organic matter and beneficial microbes. It is odourless when mature and safe to handle.</p></details>'
            . '<details><summary>How much should I use?</summary><p>Mix 1 part vermicompost with 3 parts soil when potting, then add a handful (100 to 200 g) per pot each month. For garden beds, work 1 to 2 kg per square metre into the top few inches of soil. For field crops, 1 to 2 tonnes per acre is common; your agronomist can fine-tune this for your crop.</p></details>'
            . '<details><summary>Is it safe for all plants?</summary><p>Yes. Vermicompost is gentle and can be used for vegetables, fruit trees, flowers, lawns and indoor plants. It will not burn roots.</p></details>'
            . '<details><summary>How do I buy loose vermicompost?</summary><p>Use the calculator in the <a href="{link:#loose}">Buy loose</a> section of the home page. The minimum order is {loose_min} kg and the price per kg drops as the quantity goes up. For more than {loose_max} kg, WhatsApp us on {phone} for a transport quote.</p></details>'
            . '<details><summary>Do you deliver across India?</summary><p>Yes, to PIN codes served by our courier partners. Orders are usually dispatched within {dispatch_time}.</p></details>'
            . '<details><summary>Which payment methods do you accept?</summary><p>UPI, debit and credit cards and net banking, through PayU\'s secure payment page.</p></details>'
            . '<details><summary>How can I track my order?</summary><p>Enter your order number and email on the <a href="{link:track-order}">Track order</a> page, or sign in to your account.</p></details>'
            . '<details><summary>My order arrived damaged. What do I do?</summary><p>WhatsApp us within 48 hours of delivery with your order number, an unboxing video and photos. We will arrange a replacement or refund under our <a href="{link:return-policy}">return policy</a>.</p></details>'
            . '<details><summary>Can I cancel my order?</summary><p>Yes, until it is dispatched. WhatsApp or email us your order number. See the <a href="{link:cancellation-policy}">cancellation policy</a>.</p></details>'],
        'shipping-policy' => ['Shipping policy', '',
            '<p>Last updated: {updated}.</p><h2>Where we deliver</h2><p>We deliver across India to PIN codes served by our courier partners. Large loose orders travel by road transport; we may call you to agree a delivery date and check that a vehicle can reach the unloading point.</p>'
            . '<h2>Dispatch time</h2><p>Orders are usually dispatched within {dispatch_time} of payment. Weather, public holidays or transport disruptions can delay dispatch; we will tell you if that happens.</p>'
            . '<h2>Delivery time</h2><ul><li>Metro cities: 2 to 5 business days after dispatch.</li><li>Other locations: 4 to 8 business days after dispatch.</li><li>Loose bulk orders: we confirm the expected date when we confirm your order.</li></ul>'
            . '<h2>Charges</h2><table><thead><tr><th>Order value</th><th>Shipping</th></tr></thead><tbody><tr><td>Below {free_shipping_above}</td><td>{shipping_fee}</td></tr><tr><td>{free_shipping_above} and above</td><td>Free</td></tr></tbody></table><p>The exact charges for your order are shown at checkout before you pay.</p>'
            . '<h2>Tracking</h2><p>You can check the status of your order on the <a href="{link:track-order}">Track order</a> page.</p>'
            . '<h2>Address accuracy</h2><p>Please enter a complete address, a working phone number and a landmark.</p>'
            . '<h2>Lost, undelivered or damaged parcels</h2><p>If an order is lost or cannot be delivered for reasons on our side, we refund the full amount including shipping. Please do not accept a parcel that looks tampered with or damaged; contact us right away.</p>{contact_block}'],
        'return-policy' => ['Return policy', '',
            '<p>Last updated: {updated}.</p><p>Compost and other organic inputs are natural products that cannot be resold once opened, so we accept returns only when something went wrong with your order.</p>'
            . '<h2>What we accept</h2><ul><li>Wrong product or wrong pack size delivered.</li><li>Product damaged in transit, such as torn or leaking packs.</li><li>Items missing from your order.</li></ul>'
            . '<h2>How to raise a request</h2><ol><li>Contact us within <strong>48 hours of delivery</strong> on WhatsApp ({phone}) or email ({email}) with your order number.</li><li>Share a clear, continuous unboxing video and photos of the product and the shipping label.</li><li>We review your request within 2 working days and confirm a replacement or a refund.</li></ol>'
            . '<h2>Not returnable</h2><ul><li>Opened, used or partly used products.</li><li>Change of mind after delivery.</li><li>Natural variation in the colour, texture, moisture or smell of compost.</li><li>Loose bulk compost after unloading. Please check it while it is being unloaded.</li></ul>'
            . '<h2>Refunds</h2><p>Approved refunds follow our <a href="{link:refund-policy}">refund policy</a>.</p><h2>Return address</h2><p>{address}</p>{contact_block}'],
        'refund-policy' => ['Refund policy', '',
            '<p>Last updated: {updated}.</p><h2>When you get a refund</h2><ul><li>An order is cancelled before dispatch: full refund.</li><li>A return is approved under our <a href="{link:return-policy}">return policy</a>: refund for the affected items, or a replacement if you prefer.</li><li>An order is lost or cannot be delivered for reasons on our side: full refund including shipping.</li></ul>'
            . '<h2>How and when</h2><ul><li>Refunds go back to the original payment method through PayU within 7 to 10 working days after approval.</li><li>Banks may take a few extra days to show the credit on your statement.</li></ul>'
            . '<h2>Shipping charges</h2><p>Shipping charges are not refunded for orders that were delivered as ordered. They are refunded when the order was wrong, damaged, lost or undelivered because of us.</p>'
            . '<h2>Failed payments</h2><p>If money was deducted but your order shows the payment as failed, the amount is usually reversed by your bank or PayU within 5 to 7 working days. If it is not, send us your order number and the transaction reference.</p>{contact_block}'],
        'cancellation-policy' => ['Cancellation policy', '',
            '<p>Last updated: {updated}.</p><h2>Before dispatch</h2><p>You can cancel an order until it is dispatched. Send your order number on WhatsApp ({phone}) or email ({email}).</p>'
            . '<h2>After dispatch</h2><p>Once the order is handed to the courier or loaded for transport, it can no longer be cancelled. If it arrives damaged or wrong, our <a href="{link:return-policy}">return policy</a> applies.</p>'
            . '<h2>Refunds for cancelled orders</h2><p>Orders cancelled before dispatch are refunded in full to the original payment method within 7 to 10 working days.</p>'
            . '<h2>Cancellations by us</h2><p>We may cancel an order if a product is out of stock, the address is not serviceable or the price was shown incorrectly. Any amount paid is refunded in full.</p>{contact_block}'],
        'privacy-policy' => ['Privacy policy', '',
            '<p>Last updated: {updated}.</p><p>This policy explains what {legal_name} ("we") collects through {site_domain} and how we use it.</p>'
            . '<h2>What we collect</h2><ul><li>Details you give us: name, email, phone number, delivery address and messages you send.</li><li>Order details: products, amounts and payment status.</li><li>Basic technical data that web servers log, such as IP address, browser type and pages visited.</li></ul>'
            . '<h2>Payments</h2><p>Online payments are processed by PayU. Card, UPI and bank details are entered on PayU\'s secure page; we never see or store them. We receive the payment status and transaction references.</p>'
            . '<h2>How we use your information</h2><ul><li>To process, deliver and support your orders.</li><li>To send order updates and password-reset links.</li><li>To keep accounts secure and meet tax and legal record-keeping duties.</li></ul><p>We do not sell your data and we do not send marketing messages without your consent.</p>'
            . '<h2>Who we share it with</h2><p>Only the service providers needed to run your order: courier and transport partners (name, address, phone), PayU (order amount and contact details) and our email provider.</p>'
            . '<h2>Cookies</h2><p>We use only essential cookies to keep you signed in and to remember your cart.</p>'
            . '<h2>Keeping and deleting data</h2><p>We keep order records for as long as tax law requires. You can ask us to correct your details or delete your account by emailing {email}. We reply within 30 days.</p>'
            . '<h2>Security</h2><p>The site uses HTTPS and passwords are stored only as secure hashes.</p>{contact_block}'],
        'terms-and-conditions' => ['Terms and conditions', '',
            '<p>Last updated: {updated}.</p><p>These terms apply to your use of {site_domain} and to orders placed with {legal_name}. By using the site or placing an order you agree to them.</p>'
            . '<h2>Products</h2><p>Compost and organic inputs are natural products. Colour, texture, moisture and smell vary slightly between batches. Pack weights are measured at packing; loose orders are weighed at dispatch.</p>'
            . '<h2>Prices and payment</h2><p>Prices are in Indian rupees. The final amount, including shipping, is shown at checkout before you pay. Online payments are processed by PayU. If a price was shown incorrectly because of an error, we may cancel the order and refund any amount paid.</p>'
            . '<h2>Orders</h2><p>An order is confirmed once your payment is received. We may decline or cancel an order that we cannot fulfil, for example when the address is not serviceable.</p>'
            . '<h2>Delivery, returns and refunds</h2><p>These are covered by our <a href="{link:shipping-policy}">shipping</a>, <a href="{link:return-policy}">return</a>, <a href="{link:refund-policy}">refund</a> and <a href="{link:cancellation-policy}">cancellation</a> policies.</p>'
            . '<h2>Liability</h2><p>Our liability for any order is limited to the amount you paid for it. Usage guidance on this site is general advice; results depend on soil, crop, weather and care.</p>'
            . '<h2>Governing law</h2><p>These terms are governed by the laws of India. Courts at SAS Nagar (Mohali), Punjab have jurisdiction.</p>{contact_block}'],
    ];
}

/** Loads the starting data into empty tables. Returns a short summary. */
function seed_run(PDO $pdo, bool $catalogue = true): array
{
    $done = [];
    if (!(int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn()) {
        $st = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
        foreach (seed_settings() as $k => $v) {
            $st->execute([$k, $v]);
        }
        $done[] = 'settings';
    }
    if ($catalogue && !(int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn()) {
        $st = $pdo->prepare('INSERT INTO categories (id, name, slug, sort_order, is_active) VALUES (?, ?, ?, ?, 1)');
        foreach (seed_categories() as $c) {
            $st->execute($c);
        }
        $done[] = count(seed_categories()) . ' categories';
    }
    if (!(int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn()) {
        if (!$catalogue) {
            $pdo->prepare("INSERT INTO products (id, kind, name, slug, sku, size_label, price, status) VALUES (42, 'loose', 'Loose Vermicompost (per kg)', 'loose-vermicompost', 'VC-LOOSE', 'Per kg', '14.00', 'active')")->execute();
            return $done;
        }
        $st = $pdo->prepare('INSERT INTO products (id, category_id, kind, name, slug, sku, size_label, price, description, specs, image, gallery, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $start = strtotime('2026-01-01 10:00:00');
        foreach (seed_products() as $p) {
            list($id, $cat, $name, $size, $price, $images, $desc, $specs) = $p;
            $st->execute([$id, $cat, 'standard', $name, slugify($name) . '-' . $id, 'VC-' . $id, $size, number_format($price, 2, '.', ''),
                '<p>' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</p>', $specs ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null,
                $images[0], count($images) > 1 ? json_encode(array_slice($images, 1)) : null, 'active', date('Y-m-d H:i:s', $start + $id * 3600)]);
        }
        $st->execute([42, 1, 'loose', 'Loose Vermicompost (per kg)', 'loose-vermicompost', 'VC-LOOSE', 'Per kg', '14.00',
            '<p>Loose vermicompost sold by the kilogram. The price per kg depends on the quantity; see the calculator on the home page.</p>', null, null, null, 'active', date('Y-m-d H:i:s', $start)]);
        $done[] = count(seed_products()) . ' products and the loose compost item';
    }
    if (!(int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn()) {
        $st = $pdo->prepare('INSERT INTO pages (slug, title, intro, body) VALUES (?, ?, ?, ?)');
        foreach (seed_pages() as $slug => $pg) {
            $st->execute([$slug, $pg[0], $pg[1], $pg[2]]);
        }
        $done[] = count(seed_pages()) . ' pages';
    }
    return $done;
}

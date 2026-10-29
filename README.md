# Vermi Compost store

A complete online store modelled on vermionline.in, with an admin panel and a direct PayU file import.
Plain PHP and MySQL: no framework, no Composer packages, no build step. Runs on Hostinger shared hosting.

## What is included

- **Shop:** home page with the loose-compost price calculator, shop with categories, filters and sorting, product pages,
  cart, checkout with PayU, order status and tracking, contact form, About/FAQ/policy pages, customer accounts.
- **Admin panel (`/admin/`):** dashboard, orders, PayU payments, PayU file import, customers, products, categories,
  contact messages, page editor, settings, admin users and tools.
- **Starting data:** the 22 products (same IDs and VC- product codes), 2 categories, the 30% Diwali offer, loose-compost
  rates, contact details and page content from vermionline.in.

## Requirements

PHP 7.4 or newer (8.1+ recommended) with pdo_mysql, zip, dom, xmlreader and mbstring (all standard on Hostinger),
and MySQL 5.7+ or MariaDB 10.3+.

## Install on Hostinger

1. Create an empty MySQL database and user in hPanel (Databases > MySQL Databases). Note the database name, user and password.
2. Upload the contents of this folder to `public_html` (or a sub-folder; then edit `RewriteBase` in `.htaccess`).
3. Open `https://your-domain/install.php`, fill in the database details and your admin account, and press Install.
4. **Delete `install.php` from the server.** (It also refuses to run again once `config.php` exists.)
5. Sign in at `https://your-domain/admin/`:
   - Settings > PayU: enter the merchant key and salt. Test with PayU's test keys first, then switch to Live.
   - Tools: press "Copy missing pictures" to copy the logo, home-page photos and product images from vermionline.in.
   - Settings: check the shipping fee (₹50 below ₹500 was taken from the old policy) and the dispatch time.
6. Place one small real order and refund it in PayU before announcing the site.

Install it on a subdomain first: this is a fresh database, so the live site's existing orders and customers are not in it.

## Importing a PayU file

Admin > Import PayU file. Upload the Transactions report exactly as downloaded from the PayU dashboard (.xlsx or .csv).

1. **Columns are matched automatically** from PayU's headings (`txnid`, `id`, `status`, `amount`, `addedon`, `email`, ...).
   Every one of the file's columns is kept in the original data of each transaction, whether used or not.
   You can change the matching on the preview; your choice is remembered for the next file with the same headings.
2. **Preview:** nothing is saved until you press Import. The preview shows what will be created and every row's result.
3. **Checks on every row:** transaction ID, status, amount, date and email are required. Rows with problems are listed
   and not imported. IDs that Excel turned into numbers like `4.03994E+17` are rejected, because digits were lost.
4. **No duplicates:** a transaction ID (`txnid`) or PayU ID (`mihpayid`) already in the file or the database is skipped.
   Importing the same file twice is safe. Tick "Update statuses" to apply a newer PayU status to transactions already recorded.
5. **One order per purchase, not per transaction:** attempts are grouped by an order number when the file has one
   (an order-number column, or product info such as `Order ORD-261029-7K3Q`), otherwise by the same email and amount
   within 60 minutes (Settings), ending at the first successful payment. A dropped attempt followed by a successful
   retry becomes one order with two transactions. Two successful payments become two orders, flagged as a possible duplicate payment.
6. **Orders only for real payments:** an order is created when a group has a successful, pending or refunded payment.
   Attempts that never succeeded are kept as PayU transactions without an order (Payments > Order: "No order").
7. **Nothing is invented:** imported orders are numbered `PU-<PayU ID>`, marked "PayU import", and keep only what the file says.
   Products are matched by product code, exact name, start of a single product's name, or the alternative names you add
   on a product. When product info is generic (such as "Payment"), the order has no items and says so. Unknown
   quantities, prices, shipping and addresses stay empty and show as "Not in PayU file". Delivery status is "Not recorded".
8. **Kept apart from website orders:** imported orders have their own tab, their own dashboard totals and their own
   numbering, and never appear in customer accounts.
9. **Results:** imported, already recorded and problem rows, a problem-row file (your original columns plus "Problem")
   that you can fix and upload again, a full row report, and Undo for the whole import.

Refunds are made in the PayU dashboard and recorded on the order (Orders > order > Refunds), or taken from a refund
column if the file has one.

## Database (14 tables, no foreign keys)

`admins`, `settings`, `categories`, `products`, `customers`, `orders`, `order_items`, `payu_transactions`, `payu_refunds`,
`import_batches`, `import_rows`, `contact_messages`, `pages`, `login_attempts`. Tables are linked by plain ID columns
(each indexed). `database/schema.sql` has a comment on each table.

## Folders

```
index.php shop.php shop-details.php cart.php checkout.php payu.php order-status.php track-order.php
contact.php page.php sign-in.php account.php api.php 404.php install.php router.php
includes/   app.php (bootstrap, database, helpers), store.php (prices, offer, loose compost, cart, orders, PayU),
            layout.php (shop header/footer), spreadsheet.php (.xlsx/.csv reader and writer),
            payu_import.php (the import), admin.php (admin chrome and filters), seed.php (starting data)
admin/      one file per admin page
assets/     css/, js/, img/, uploads/products/ (product images)
storage/    imports/ (uploaded PayU files), logs/ (php-errors.log); blocked from the web
database/   schema.sql
```

## Security

Passwords are stored as bcrypt hashes. Every form has a CSRF token. Sign-in attempts are rate-limited. All database access
uses prepared statements. Admin sessions use a separate cookie limited to `/admin/`, expire after 2 hours idle and are
SameSite=Strict. PayU replies are accepted only with a valid hash; the amount is checked against the order. Uploaded files
are checked by content, stored under random names, and the upload and storage folders cannot run scripts.

## Local testing

```
php -S localhost:8000 router.php
```
then open http://localhost:8000/install.php.

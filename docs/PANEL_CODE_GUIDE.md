# Panelist Code Guide

Use this as a lookup sheet during your presentation. In VS Code, open a file with **Ctrl+P**, then use **Ctrl+G** to jump to a line. You can also use **Ctrl+F** to search for the function name shown below.

## How the application is organized

The usual request path is:

1. The screen is rendered by the frontend in `frontend/src/main.js`.
2. `frontend/src/api.js` sends the request to the PHP API.
3. `backend/public/index.php` checks the route, sign-in, and permissions.
4. A service in `backend/src/Services/` validates the request and runs database queries.
5. The tables and relationships are defined in `backend/database/schema.sql`.

When explaining a feature, start at the screen, then follow the request through the API route and service to the database.

## Common panel questions

| If they ask... | Open this section | What it does |
|---|---|---|
| How does sign-in work? | [`AuthService::login()`](../backend/src/Services/AuthService.php#L64), [`POST /api/login`](../backend/public/index.php#L273) | Checks credentials and starts the signed-in session. |
| How can a user register? | [`AuthService::register()`](../backend/src/Services/AuthService.php#L126), [`POST /api/register`](../backend/public/index.php#L260) | Validates public account details and creates a customer or buyer account. |
| How are roles and permissions applied? | [`PermissionService`](../backend/src/Services/PermissionService.php#L7), [role permissions migration](../backend/database/migrations/005_role_permissions.sql#L1), [`renderRoleWorkspace()`](../frontend/src/main.js#L1782) | Controls which modules and API actions each role can use. |
| How is an animal added? | [`renderLivestockModule()`](../frontend/src/main.js#L1620), [`POST /api/animals`](../backend/public/index.php#L407), [`AnimalService::create()`](../backend/src/Services/AnimalService.php#L23) | Displays the form, checks permission, validates the record, and inserts an active animal. |
| How can animal details be edited? | [`renderAnimalProfile()`](../frontend/src/main.js#L910), [`PATCH /api/animals/{id}`](../backend/public/index.php#L428), [`AnimalService::updateProfile()`](../backend/src/Services/AnimalService.php#L74) | Saves profile fields and prevents duplicate tags or species changes after order allocation. |
| How are health records saved? | [`renderHealthModule()`](../frontend/src/main.js#L1628), [`HealthService::create()`](../backend/src/Services/HealthService.php#L51) | Records animal care and follow-up information. |
| How is a livestock listing stocked? | [`CommerceService::products()`](../backend/src/Services/CommerceService.php#L13), [`products` table](../backend/database/schema.sql#L232) | Shows active listings and calculates livestock stock from active, unallocated animals. |
| How does a buyer request livestock? | [`Livestock catalogue UI`](../frontend/src/main.js#L1904), [`POST /api/orders`](../backend/public/index.php#L693), [`CommerceService::createCustomerOrder()`](../backend/src/Services/CommerceService.php#L54) | Checks the buyer's role, listing, whole-animal quantity, and available stock, then saves the order and item. |
| How does the farm accept and fulfil an order? | [`updateOrdersBulk()`](../backend/src/Services/CommerceService.php#L221), [`reserveOrderItems()`](../backend/src/Services/CommerceService.php#L409), [`recordOrderSales()`](../backend/src/Services/CommerceService.php#L460) | Allocates unique animals on acceptance and records sales at fulfilment. A failed bulk allocation rolls the whole bulk action back. |
| How are cash or cheque payments confirmed? | [`Record payment UI`](../frontend/src/main.js#L1172), [`createPayment()`](../backend/src/Services/CommerceService.php#L310), [`requestOfflinePayment()`](../backend/src/Services/CommerceService.php#L344) | Buyer selections stay pending. Farm staff record cash or cheque receipt to confirm the payment. |
| Is M-Pesa live? | [`M-Pesa simulation`](../backend/src/Services/MpesaService.php#L94), [`POST /api/payments/mpesa/initiate`](../backend/public/index.php#L680) | No. This demo creates a local simulated payment and does not contact Daraja or move money. |
| How does feedback work for users and admins? | [`role feedback UI`](../frontend/src/main.js#L1992), [`admin feedback UI`](../frontend/src/main.js#L1760), [`FeedbackService::resolveAll()`](../backend/src/Services/FeedbackService.php#L51), [`resolve-all API route`](../backend/public/index.php#L874) | Users submit and see their own feedback. Admins can review and resolve messages individually or in bulk. |
| Where are users, animals, orders, and payments stored? | [`schema.sql`](../backend/database/schema.sql#L13) | Defines the MySQL tables, fields, constraints, and relationships. Useful starting points: users (line 20), animals (79), products (232), orders (250), order items (268), animal allocations (281), payments (309), feedback (350). |
| How does the application connect to MySQL? | [`Database::connection()`](../backend/src/Core/Database.php#L12), [`Env::load()`](../backend/src/Core/Env.php#L11) | Reads connection settings from environment variables, including the ignored `.env.local` file. Credentials are not hard-coded in the source. |
| Where do sample records come from? | [`DemoService::seed()`](../backend/src/Services/DemoService.php#L12), [`import_synthetic.php`](../backend/bin/import_synthetic.php#L1) | Creates clearly labelled demonstration data for walkthroughs; it is not farm measurement data. |

## How to explain a code path

For example, if asked how an animal order is accepted:

1. Start with `updateOrdersBulk()` to show the admin action and transaction.
2. Open `reserveOrderItems()` to show how it selects active, unallocated animals and marks them sold.
3. Show the `order_animals` table in `schema.sql`, which links each animal to one order and has a unique constraint on `animal_id`.
4. Explain that `recordOrderSales()` creates the sale record when the order is fulfilled.

## Database location

The project code is in this repository, but the live development data is in MySQL. The current local setup uses the Docker container `flouri-mysql`, database `lfmis`, and Docker volume `flouri_data`, mounted inside the container at `/var/lib/mysql`. Docker Desktop manages the volume's physical location. The schema is source code; the stored records are in the volume.

## About comments

The source is not commented line by line. Comments and docblocks mark key behavior and constraints, while this guide provides the broader feature map. Be ready to explain the data flow in your own words rather than relying on comments alone.

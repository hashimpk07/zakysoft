# Appstation Pvt Ltd.
<ul>
    <li>Project Created By  : HASHIM PK  </li>
    <li>Project Created 0n  : 07/07/2025 to 07/07/2025 </li>
     <li>Time Duration      : 3 to 4 hours ( include documentation)  </li>
    <li>Using Technology    : Laravel 12.19.3 and Mysql  </li>
</ul>


<h2 style="font-weight: bold";>About Application</h2>
<h4> PHP Laravel Machine Test: API Rate Limiting &amp;
You’re building an API for a warehouse inventory system that tracks products,
stock movements, and generates reports. The system must handle large
datasets efficiently.</p>

<h4>User Authentication</h4>
<ul> 
<li>Use Laravel Sanctum or Passport for API token-based authentication.</li>
</ul>

<h4>Features</h4>
<ul>
  <li>Laravel Sanctum authentication.</li>
  <li>Laravel API integration.</li>
  <li>Event and Listener.</li>
  <li>Background jobs with Laravel Queue.</li>
  <li>Migration.</li>
  <li>Seed (bulk data using factories).</li>
  <li>Tinker.</li>
</ul>

<h4>Technologies</h4>
<ul>
  <li> Laravel 12.19.3  Version.</li>
  <li>Sanctum </li>
  <li>Mysql</li>
  <li> Laravel Queue (Mysql DB ) </li>
  <li> Laravel Event and Listene </li>
</ul>

<h2 style="font-weight: bold;">How to Execute the Project</h2>
<ul>
    <li><strong>Step 1:</strong> Clone the project:<br><code>git clone https://github.com/hashimpk07/appstation.git</code></li>
    <li><strong>Step 2:</strong> Create a <code>.env</code> file from <code>.env.example</code>:</li>
    <li><strong>Step 3:</strong> Update the <code>.env</code> file with the following DB settings:<br>
        <code>
            DB_CONNECTION=mysql<br>
            DB_HOST=127.0.0.1<br>
            DB_PORT=3306<br>
            DB_DATABASE=zakysoft<br>
            DB_USERNAME=root<br>
            DB_PASSWORD=
        </code>
    </li>
  <li><strong>Step 4:</strong> Install project dependencies:<br><code>composer install</code> or <code>composer update</code></li>
  
  <li><strong>Step 5:</strong> Ensure the <code>vendor/</code> folder is created successfully.</li>
  
  <li><strong>Step 6:</strong> Make sure the database <code>appstation</code> exists in your DB server.</li>
  
  <li><strong>Step 7:</strong> Run migrations to create the necessary tables:<br><code>php artisan migrate</code></li>
  
  <li><strong>Step 8:</strong> Run seeders to populate initial data:<br><code>php artisan db:seed</code></li>
  
  <li><strong>Step 9:</strong> (Optional) Create a test user via Laravel Tinker:<br>
    <code>php artisan tinker</code><br>
    <code>\App\Models\User::create(['name' => 'hashim', 'email' => 'hashimpk04@gmail.com', 'password' => bcrypt('12345678')]);</code>
  </li>
  
  <li><strong>Step 10:</strong> Publish Sanctum configuration:<br>
    <code>php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"</code>
  </li>
  
  <li><strong>Step 11:</strong> Ensure proper environment setup in <code>.env</code> file:<br>
    <code>QUEUE_CONNECTION=database</code>
  </li>
  
  <li><strong>Step 12:</strong> Create the queue jobs table and re-run migration if needed:<br>
    <code>php artisan queue:table && php artisan migrate</code>
  </li>
  
  <li><strong>Step 13:</strong> Start the queue worker (in a separate terminal):<br>
    <code>php artisan queue:work</code>
  </li>
  
  <li><strong>Step 15:</strong> Start the application server:<br>
    <code>php artisan serve</code>
  </li>
  
  <li><strong>Step 16:</strong> Open the served URL in your browser:<br>
    <code>http://127.0.0.1:8000</code>
  </li>
</ul>



<h2 style="font-weight: bold;">Available API Endpoints</h2>
<table border="1" cellpadding="6" cellspacing="0">
  <thead>
    <tr>
      <th>Method</th>
      <th>Endpoint</th>
      <th>Description</th>
      <th>Authentication</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>POST</td>
      <td><code>/api/login</code></td>
      <td>Login and return Sanctum token</td>
      <td>No</td>
    </tr>
      <tr>
      <td>GET</td>
      <td><code>/api/inventory/report</code></td>
      <td> Generate a report showing the current stock level for each product across all warehouses </td></td>
      <td>No</td>
    </tr>
     </tr>
      <tr>
      <td>POST</td>
      <td><code>/api/stock-movements</code></td>
      <td> Record a new stock movement (authenticated users only) </td></td>
      <td>Yes</td>
    </tr>
    
  </tbody>
</table>

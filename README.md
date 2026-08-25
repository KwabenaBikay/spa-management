# Spa Management System

A comprehensive web-based spa management system with separate admin and frontdesk interfaces.

## Features

- **Admin Dashboard**: Manage staff, services, massage types, and view reports
- **Frontdesk Interface**: Add and manage clients, view appointments
- **Staff Sections**: Dedicated areas for different spa services (massage, facials, nails, salon, barbering)
- **Beautiful UI**: Apple-style gradient backgrounds with glassmorphism effects
- **Responsive Design**: Works on desktop, tablet, and mobile devices

## Default Login Credentials

### Admin Access
- **Username**: `admin`
- **Password**: `admin123` *(Change password upon first login)*
- **Access**: Full system management

## Database Setup

1. Import `schema.sql` into your MySQL database (`spa_db`).
2. Update database connection in `db.php` if your MySQL port/credentials differ from default XAMPP settings.

## Local Development

1. Clone the repository into your web root directory (e.g. `htdocs/`).
2. Start Apache and MySQL via XAMPP.
3. Import `schema.sql` via phpMyAdmin or MySQL CLI.
4. Configure database connection in `db.php`.
5. Access the application in your browser at `http://localhost/spa-management`.

## File Structure

```
spa-management/
├── admin/                 # Admin & Management pages
├── api/                   # REST / AJAX API endpoints
├── assets/                # CSS, JS, audio, and images
├── frontdesk/             # Frontdesk interface
├── sections/              # Service queue sections (salon, massage, nails, etc.)
├── db.php                 # Database connection configuration
├── index.php              # Main landing / queue overview page
├── login.php              # General staff login
├── schema.sql             # Clean database schema & initial seed data
└── README.md              # Documentation
```

## Technologies Used

- **Backend**: PHP, MySQL
- **Frontend**: HTML5, CSS3, JavaScript
- **Styling**: Custom CSS with gradient backgrounds & glassmorphism
- **Icons**: Font Awesome

## License

This project is for educational and business use.
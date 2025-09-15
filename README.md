# Cookbook Admin Panel 📚👨‍🍳

เว็บแอดมินสำหรับจัดการระบบสูตรอาหาร (Recipe Management System) พร้อมระบบผู้ใช้งาน, การจัดการวัตถุดิบ, และคุณสมบัติการบริหารจัดการที่ครบครัน

A comprehensive admin panel for managing cookbook recipes, ingredients, users, and media with full administrative features.

## ✨ Features / คุณสมบัติ

### 🍳 Recipe Management / จัดการสูตรอาหาร
- ✅ Create, edit, delete recipes / สร้าง แก้ไข ลบสูตรอาหาร
- ✅ Recipe status management (draft/review/published/archived) / จัดการสถานะสูตร
- ✅ Recipe categorization and tagging / จัดหมวดหมู่และแท็กสูตร
- ✅ Recipe cover image management / จัดการภาพปกสูตร
- ✅ Recipe sorting (latest/popular/trending/recommended) / เรียงลำดับสูตร

### 🥕 Ingredient Management / จัดการวัตถุดิบ
- ✅ Add and manage ingredients / เพิ่มและจัดการวัตถุดิบ
- ✅ Ingredient categorization / จัดหมวดหมู่วัตถุดิบ
- ✅ Ingredient image upload / อัปโหลดรูปภาพวัตถุดิบ
- ✅ Allergy information management / จัดการข้อมูลสารก่อภูมิแพ้

### 👥 User Management / จัดการผู้ใช้
- ✅ User account management / จัดการบัญชีผู้ใช้
- ✅ Role-based access control (RBAC) / ระบบควบคุมสิทธิ์ตามบทบาท
- ✅ User authentication and authorization / ระบบยืนยันตัวตนและสิทธิ์

### 📸 Media Library / คลังไฟล์สื่อ
- ✅ File upload and management / อัปโหลดและจัดการไฟล์
- ✅ Media health monitoring / ตรวจสอบสุขภาพไฟล์สื่อ
- ✅ Image optimization / ปรับปรุงภาพให้เหมาะสม

### 💬 Review System / ระบบรีวิว
- ✅ Recipe review management / จัดการรีวิวสูตรอาหาร
- ✅ Review status updates / อัปเดตสถานะรีวิว
- ✅ Comment moderation / ดูแลความคิดเห็น

### 📊 Dashboard & Analytics / แดชบอร์ดและการวิเคราะห์
- ✅ Admin dashboard with statistics / แดชบอร์ดแอดมินพร้อมสถิติ
- ✅ Data quality alerts / แจ้งเตือนคุณภาพข้อมูล
- ✅ Recent activity tracking / ติดตามกิจกรรมล่าสุด
- ✅ Audit logging / บันทึกการตรวจสอบ

### 🔒 Security Features / ความปลอดภัย
- ✅ CSRF protection / ป้องกัน CSRF
- ✅ SQL injection prevention / ป้องกัน SQL injection
- ✅ Session management / จัดการ session
- ✅ Password reset functionality / รีเซ็ตรหัสผ่าน

## 🚀 Installation / การติดตั้ง

### Prerequisites / ข้อกำหนดเบื้องต้น
- PHP 7.4+ with MySQLi extension
- MySQL/MariaDB 5.7+
- Web server (Apache/Nginx)
- `gd` extension for image processing

### Setup Steps / ขั้นตอนการติดตั้ง

1. **Clone the repository / โคลนโปรเจกต์**
   ```bash
   git clone https://github.com/Hakuma17/cookbook_admin.git
   cd cookbook_admin
   ```

2. **Database Setup / ตั้งค่าฐานข้อมูล**
   - Create a MySQL database named `cookbookweb_db`
   - สร้างฐานข้อมูล MySQL ชื่อ `cookbookweb_db`

3. **Configure Database Connection / ตั้งค่าการเชื่อมต่อฐานข้อมูล**

   Edit `includes/db.php`:
   ```php
   $dbHost = 'localhost';        // Database host
   $dbUser = 'your_username';    // Database username
   $dbPass = 'your_password';    // Database password  
   $dbName = 'cookbookweb_db';   // Database name
   ```

4. **Configure Base Path / ตั้งค่า Base Path**

   Edit `includes/config.php`:
   ```php
   define('BASE_URL', '/cookbook_admin'); // Adjust based on your setup
   ```

5. **Set File Permissions / ตั้งค่าสิทธิ์ไฟล์**
   ```bash
   chmod 755 uploads/
   chmod 755 uploads/ingredients/
   chmod 755 uploads/recipes/
   chmod 755 uploads/profiles/
   chmod 755 uploads/media/
   ```

6. **Access the Application / เข้าใช้งานแอปพลิเคชัน**

   Navigate to: `http://your-domain/cookbook_admin`

## 📁 Project Structure / โครงสร้างโปรเจกต์

```
cookbook_admin/
├── admin/                  # Additional admin tools
├── assets/                 # Static assets (CSS, JS, images)
│   ├── css/
│   ├── js/
│   └── img/
├── auth/                   # Authentication pages
│   ├── login.php
│   ├── login_process.php
│   └── logout.php
├── includes/               # Core includes
│   ├── config.php         # Configuration settings
│   ├── db.php             # Database connection
│   ├── header.php         # Common header
│   ├── footer.php         # Common footer
│   ├── rbac.php           # Role-based access control
│   ├── csrf.php           # CSRF protection
│   ├── media.php          # Media handling
│   ├── audit.php          # Audit logging
│   └── helpers.php        # Helper functions
├── tools/                  # Utility tools
├── uploads/                # File upload directory
│   ├── ingredients/
│   ├── recipes/
│   ├── profiles/
│   └── media/
├── index.php              # Dashboard
├── manage_recipes.php     # Recipe management
├── manage_ingredients.php # Ingredient management
├── manage_users.php       # User management
├── manage_reviews.php     # Review management
├── media_library.php      # Media library
├── recipe_form.php        # Recipe form
├── ingredient_form.php    # Ingredient form
└── README.md              # This file
```

## 🎯 Main Features Detail / รายละเอียดคุณสมบัติหลัก

### Dashboard Features / คุณสมบัติแดชบอร์ด
- **Statistics Overview** / สถิติภาพรวม: Total counts and weekly comparisons
- **Data Quality Alerts** / แจ้งเตือนคุณภาพข้อมูล: Missing images, uncategorized items
- **Recent Activity** / กิจกรรมล่าสุด: Latest recipes and reviews
- **Quick Actions** / การดำเนินการด่วน: Fast access to common tasks

### Recipe Management Features / คุณสมบัติจัดการสูตร
- **Multi-status Workflow** / เวิร์กโฟลว์หลายสถานะ: Draft → Review → Published → Archived
- **Rich Media Support** / รองรับสื่อหลากหลาย: Cover images, step-by-step photos
- **Categorization** / การจัดหมวดหมู่: Categories and tags
- **Ingredient Integration** / เชื่อมโยงวัตถุดิบ: Link recipes with ingredients

### Security Implementation / การใช้งานความปลอดภัย
- **CSRF Tokens** / โทเค็น CSRF: All forms protected
- **SQL Prepared Statements** / คำสั่ง SQL เตรียมไว้: Prevent injection attacks
- **Role-based Access** / การเข้าถึงตามบทบาท: Different permission levels
- **Session Security** / ความปลอดภัย Session: Secure session handling

## 🔧 Configuration / การตั้งค่า

### Environment Configuration / การตั้งค่าสภาพแวดล้อม

1. **Database Configuration** / การตั้งค่าฐานข้อมูล (`includes/db.php`)
2. **Base URL Configuration** / การตั้งค่า URL หลัก (`includes/config.php`)
3. **Upload Directory** / โฟลเดอร์อัปโหลด: Ensure proper permissions
4. **PHP Settings** / การตั้งค่า PHP: Recommended settings:
   ```ini
   upload_max_filesize = 10M
   post_max_size = 10M
   max_execution_time = 300
   memory_limit = 256M
   ```

## 🛠️ Development / การพัฒนา

### Code Style / รูปแบบโค้ด
- **PHP Standards** / มาตรฐาน PHP: Follow PSR-12 coding standards
- **Database** / ฐานข้อมูล: Use prepared statements
- **Security** / ความปลอดภัย: Validate and sanitize all inputs
- **Comments** / ความคิดเห็น: Code comments in Thai and English

### Adding New Features / การเพิ่มฟีเจอร์ใหม่
1. Follow the existing file structure
2. Use the RBAC system for access control
3. Implement CSRF protection for forms
4. Add audit logging for important actions
5. Update navigation in `includes/header.php`

## 📱 Responsive Design / การออกแบบ Responsive

The admin panel is built with Bootstrap 5 and includes:
- **Mobile-first approach** / แนวทางมือถือเป็นหลัก
- **Touch-friendly interface** / อินเทอร์เฟซเป็นมิตรกับการสัมผัส
- **Optimized tables** / ตารางที่ปรับให้เหมาะสม
- **Responsive navigation** / การนำทางแบบ responsive

## 🚨 Troubleshooting / การแก้ไขปัญหา

### Common Issues / ปัญหาที่พบบ่อย

1. **Database Connection Error** / ข้อผิดพลาดการเชื่อมต่อฐานข้อมูล
   - Check database credentials in `includes/db.php`
   - Ensure MySQL service is running

2. **File Upload Issues** / ปัญหาการอัปโหลดไฟล์
   - Check folder permissions (755 or 777)
   - Verify PHP upload settings

3. **Session Issues** / ปัญหา Session
   - Ensure session directory is writable
   - Check PHP session configuration

4. **Permission Denied** / ปฏิเสธสิทธิ์
   - Verify user roles in database
   - Check RBAC configuration

## 🤝 Contributing / การมีส่วนร่วม

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## 📄 License / ลิขสิทธิ์

โปรเจคนี้ทำขึ้นเพื่อการศึกษา
## 📞 Support / การสนับสนุน

For questions or support, please:
- Create an issue on GitHub
- Contact the repository maintainer

---

**Made with ❤️ for Thai cuisine lovers** / **สร้างด้วย ❤️ สำหรับคนรักอาหารไทย**

Last updated: 2025

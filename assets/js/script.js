/**
 * assets/js/script.js
 * - utility ทั่วไปของแอดมิน
 * - ไม่ใช้ ES module (header/footers โหลดแบบปกติ) => ผูกไว้ที่ window
 * - เพิ่มอนิเมชั่นและ Interactive Features
 */

// ---------- Helper: แสดงข้อความแบบ Bootstrap Alert ชั่วคราว ----------
(function(){
  function showFlashMessage(message, type = 'success', ms = 2500){
    // type: 'primary'|'success'|'danger'|'warning'|'info'...
    var wrap = document.createElement('div');
    wrap.innerHTML = (
      '<div class="alert alert-'+type+' shadow-lg position-fixed top-0 start-50 translate-middle-x mt-3 fade-in" ' +
      'role="alert" style="z-index:1080; min-width:320px; text-align:center; border-radius: 12px; border: none;">' +
      '<div class="d-flex align-items-center justify-content-center gap-2">' +
      '<i class="bi bi-check-circle-fill"></i>' +
      (message || '') +
      '</div>' +
      '</div>'
    );
    var el = wrap.firstElementChild;
    document.body.appendChild(el);
    
    // Add entrance animation
    setTimeout(function(){ 
      el.style.transform = 'translateY(-20px)';
      el.style.opacity = '0';
      el.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
      
      requestAnimationFrame(function() {
        el.style.transform = 'translateY(0)';
        el.style.opacity = '1';
      });
    }, 20);
    
    // Auto remove with exit animation
    setTimeout(function(){
      el.style.transform = 'translateY(-20px)';
      el.style.opacity = '0';
      setTimeout(function(){ el.remove(); }, 300);
    }, ms);
  }
  window.showFlashMessage = showFlashMessage;
})();

// ---------- Animation Utilities ----------
(function(){
  // Intersection Observer for scroll animations
  function initScrollAnimations() {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('fade-in');
          observer.unobserve(entry.target);
        }
      });
    }, {
      threshold: 0.1,
      rootMargin: '0px 0px -50px 0px'
    });

    // Observe all cards and stats
    document.querySelectorAll('.cb-card, .cb-stat, .cb-quick .card').forEach(el => {
      observer.observe(el);
    });
  }

  // Add hover effects to interactive elements
  function initHoverEffects() {
    // Add ripple effect to buttons
    document.querySelectorAll('.btn').forEach(btn => {
      btn.addEventListener('click', function(e) {
        const ripple = document.createElement('span');
        const rect = this.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height);
        const x = e.clientX - rect.left - size / 2;
        const y = e.clientY - rect.top - size / 2;
        
        ripple.style.cssText = `
          position: absolute;
          width: ${size}px;
          height: ${size}px;
          left: ${x}px;
          top: ${y}px;
          background: rgba(255,255,255,0.3);
          border-radius: 50%;
          transform: scale(0);
          animation: ripple 0.6s linear;
          pointer-events: none;
        `;
        
        this.style.position = 'relative';
        this.style.overflow = 'hidden';
        this.appendChild(ripple);
        
        setTimeout(() => ripple.remove(), 600);
      });
    });

    // Add CSS for ripple animation
    const style = document.createElement('style');
    style.textContent = `
      @keyframes ripple {
        to {
          transform: scale(4);
          opacity: 0;
        }
      }
    `;
    document.head.appendChild(style);
  }

  // Add loading states to forms
  function initFormLoading() {
    document.querySelectorAll('form').forEach(form => {
      form.addEventListener('submit', function() {
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>กำลังประมวลผล...';
          submitBtn.classList.add('loading');
        }
      });
    });
  }

  // Add smooth scrolling for anchor links
  function initSmoothScrolling() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function(e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
          target.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
          });
        }
      });
    });
  }

  // Add keyboard navigation enhancements
  function initKeyboardNavigation() {
    document.addEventListener('keydown', function(e) {
      // Escape key to close modals
      if (e.key === 'Escape') {
        const openModal = document.querySelector('.modal.show');
        if (openModal) {
          const modal = bootstrap.Modal.getInstance(openModal);
          if (modal) modal.hide();
        }
      }
      
      // Enter key to submit forms
      if (e.key === 'Enter' && e.target.matches('input[type="text"], input[type="email"], input[type="password"]')) {
        const form = e.target.closest('form');
        if (form && !e.target.matches('textarea')) {
          e.preventDefault();
          form.requestSubmit();
        }
      }
    });
  }

  // Add tooltip enhancements
  function initTooltips() {
    // Initialize Bootstrap tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl, {
        animation: true,
        delay: { show: 300, hide: 100 }
      });
    });
  }

  // Add table enhancements
  function initTableEnhancements() {
    // Add sorting indicators
    document.querySelectorAll('.table th[data-sort]').forEach(th => {
      th.style.cursor = 'pointer';
      th.addEventListener('click', function() {
        // Remove active class from all headers
        document.querySelectorAll('.table th').forEach(h => h.classList.remove('active'));
        // Add active class to clicked header
        this.classList.add('active');
      });
    });

    // Add row selection
    document.querySelectorAll('.table tbody tr').forEach(row => {
      row.addEventListener('click', function(e) {
        if (!e.target.matches('button, a, input, select')) {
          this.classList.toggle('table-active');
        }
      });
    });
  }

  // Add search enhancements
  function initSearchEnhancements() {
    const searchInputs = document.querySelectorAll('input[type="search"], input[name="q"]');
    searchInputs.forEach(input => {
      let searchTimeout;
      
      input.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
          // Add loading state
          this.style.background = 'linear-gradient(90deg, #f8f9fa 0%, #e9ecef 50%, #f8f9fa 100%)';
          this.style.backgroundSize = '200% 100%';
          this.style.animation = 'shimmer 1.5s infinite';
          
          // Simulate search delay
          setTimeout(() => {
            this.style.background = '';
            this.style.animation = '';
          }, 500);
        }, 300);
      });
    });
  }

  // Add mobile menu enhancements
  function initMobileMenu() {
    const navbarToggler = document.querySelector('.navbar-toggler');
    const navbarCollapse = document.querySelector('.navbar-collapse');
    
    if (navbarToggler && navbarCollapse) {
      navbarToggler.addEventListener('click', function() {
        navbarCollapse.classList.toggle('show');
      });
      
      // Close menu when clicking outside
      document.addEventListener('click', function(e) {
        if (!navbarToggler.contains(e.target) && !navbarCollapse.contains(e.target)) {
          navbarCollapse.classList.remove('show');
        }
      });
    }
  }

  // Add progress indicators
  function initProgressIndicators() {
    // Add progress bar to forms
    document.querySelectorAll('form').forEach(form => {
      const fields = form.querySelectorAll('input[required], select[required], textarea[required]');
      const progressBar = document.createElement('div');
      progressBar.className = 'progress mb-3';
      progressBar.innerHTML = '<div class="progress-bar" role="progressbar" style="width: 0%"></div>';
      
      form.insertBefore(progressBar, form.firstElementChild);
      
      fields.forEach(field => {
        field.addEventListener('input', updateProgress);
        field.addEventListener('change', updateProgress);
      });
      
      function updateProgress() {
        const filled = Array.from(fields).filter(field => field.value.trim() !== '').length;
        const percentage = (filled / fields.length) * 100;
        progressBar.querySelector('.progress-bar').style.width = percentage + '%';
      }
    });
  }

  // Initialize all enhancements
  function init() {
    initScrollAnimations();
    initHoverEffects();
    initFormLoading();
    initSmoothScrolling();
    initKeyboardNavigation();
    initTooltips();
    initTableEnhancements();
    initSearchEnhancements();
    initMobileMenu();
    initProgressIndicators();
  }

  // Run when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

// ---------- รันหลัง DOM พร้อม ----------
document.addEventListener('DOMContentLoaded', function () {
  // Add page load animation
  document.body.classList.add('page-enter');
  
  // Show welcome message for new users
  if (!localStorage.getItem('admin_visited')) {
    setTimeout(() => {
      window.showFlashMessage('ยินดีต้อนรับสู่ระบบจัดการ Cookbook Admin!', 'info', 3000);
      localStorage.setItem('admin_visited', 'true');
    }, 1000);
  }
  
  // Add click animations to cards
  document.querySelectorAll('.cb-card, .card').forEach(card => {
    card.addEventListener('click', function(e) {
      if (!e.target.matches('button, a, input, select')) {
        this.style.transform = 'scale(0.98)';
        setTimeout(() => {
          this.style.transform = '';
        }, 150);
      }
    });
  });
});

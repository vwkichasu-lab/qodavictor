/**
 * Security Module - Anti-cheating measures for exam interface
 */

class ExamSecurity {
  constructor(examId, studentId, apiEndpoint) {
    this.examId = examId;
    this.studentId = studentId;
    this.apiEndpoint = apiEndpoint;
    this.violations = [];
    this.isLocked = false;
    this.warningCount = 0;
    this.maxWarnings = 3;
    
    this.init();
  }

  init() {
    this.setupEventListeners();
    this.startMonitoring();
  }

  setupEventListeners() {
    // Prevent right-click
    document.addEventListener('contextmenu', (e) => {
      e.preventDefault();
      this.logViolation('RIGHT_CLICK', 'Right click attempted');
      return false;
    });

    // Prevent copy
    document.addEventListener('copy', (e) => {
      e.preventDefault();
      this.logViolation('COPY_ATTEMPT', 'Copy attempted');
      return false;
    });

    // Prevent paste
    document.addEventListener('paste', (e) => {
      e.preventDefault();
      this.logViolation('PASTE_ATTEMPT', 'Paste attempted');
      return false;
    });

    // Prevent cut
    document.addEventListener('cut', (e) => {
      e.preventDefault();
      this.logViolation('COPY_ATTEMPT', 'Cut attempted');
      return false;
    });

    // Detect tab/window blur
    window.addEventListener('blur', () => {
      this.logViolation('TAB_SWITCH', 'Window lost focus');
    });

    // Detect fullscreen exit
    document.addEventListener('fullscreenchange', () => {
      if (!document.fullscreenElement) {
        this.logViolation('FULLSCREEN_EXIT', 'Fullscreen exited');
      }
    });

    // Detect DevTools
    this.detectDevTools();

    // Prevent keyboard shortcuts
    document.addEventListener('keydown', (e) => {
      // Prevent F12 (DevTools)
      if (e.key === 'F12') {
        e.preventDefault();
        this.logViolation('DEVTOOLS_OPEN', 'F12 pressed');
        return false;
      }

      // Prevent Ctrl+Shift+I/J/C (DevTools)
      if (e.ctrlKey && e.shiftKey && ['I', 'J', 'C'].includes(e.key)) {
        e.preventDefault();
        this.logViolation('DEVTOOLS_OPEN', `Ctrl+Shift+${e.key} pressed`);
        return false;
      }

      // Prevent Ctrl+U (View Source)
      if (e.ctrlKey && e.key === 'u') {
        e.preventDefault();
        return false;
      }

      // Prevent Ctrl+S (Save)
      if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        return false;
      }

      // Prevent Ctrl+P (Print)
      if (e.ctrlKey && e.key === 'p') {
        e.preventDefault();
        return false;
      }

      // Prevent Alt+Tab detection (via visibility API)
      if (e.altKey && e.key === 'Tab') {
        this.logViolation('TAB_SWITCH', 'Alt+Tab detected');
      }
    });

    // Visibility API
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        this.logViolation('TAB_SWITCH', 'Tab switched or minimized');
      }
    });
  }

  detectDevTools() {
    const threshold = 160;
    const checkDevTools = () => {
      const widthThreshold = window.outerWidth - window.innerWidth > threshold;
      const heightThreshold = window.outerHeight - window.innerHeight > threshold;
      
      if (widthThreshold || heightThreshold) {
        this.logViolation('DEVTOOLS_OPEN', 'DevTools detected');
      }
    };

    setInterval(checkDevTools, 1000);
  }

  startMonitoring() {
    // Request fullscreen on start
    this.requestFullscreen();

    // Check lock status periodically
    setInterval(() => this.checkLockStatus(), 5000);
  }

  requestFullscreen() {
    const elem = document.documentElement;
    if (elem.requestFullscreen) {
      elem.requestFullscreen().catch(err => {
        console.log('Fullscreen request denied:', err);
      });
    }
  }

  async logViolation(type, message, details = {}) {
    if (this.isLocked) return;

    this.warningCount++;
    this.violations.push({
      type,
      message,
      timestamp: new Date().toISOString(),
      details,
    });

    // Show warning to student
    this.showWarning(message);

    // Send to server
    try {
      const response = await fetch(`${this.apiEndpoint}/security/violation`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
        },
        body: JSON.stringify({
          examId: this.examId,
          violationType: type,
          details: { message, ...details },
        }),
      });

      const data = await response.json();
      
      if (data.data?.shouldLock) {
        this.lockScreen();
      }
    } catch (error) {
      console.error('Failed to log violation:', error);
    }

    // Auto-lock after max warnings
    if (this.warningCount >= this.maxWarnings) {
      this.lockScreen();
    }
  }

  showWarning(message) {
    // Remove existing warning
    const existing = document.getElementById('security-warning');
    if (existing) existing.remove();

    const warning = document.createElement('div');
    warning.id = 'security-warning';
    warning.style.cssText = `
      position: fixed;
      top: 20px;
      left: 50%;
      transform: translateX(-50%);
      background: #dc2626;
      color: white;
      padding: 16px 24px;
      border-radius: 8px;
      z-index: 10000;
      font-weight: 600;
      box-shadow: 0 4px 12px rgba(0,0,0,0.3);
      animation: slideDown 0.3s ease;
    `;
    warning.innerHTML = `
      <div style="display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 20px;">⚠️</span>
        <div>
          <div>Security Violation Detected</div>
          <div style="font-size: 12px; opacity: 0.9; margin-top: 4px;">${message}</div>
          <div style="font-size: 11px; margin-top: 4px;">Warning ${this.warningCount}/${this.maxWarnings}</div>
        </div>
      </div>
    `;

    document.body.appendChild(warning);

    // Auto-remove after 5 seconds
    setTimeout(() => {
      warning.remove();
    }, 5000);
  }

  lockScreen() {
    if (this.isLocked) return;
    this.isLocked = true;

    const lockOverlay = document.createElement('div');
    lockOverlay.id = 'exam-lock-overlay';
    lockOverlay.style.cssText = `
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0,0,0,0.95);
      z-index: 99999;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: white;
      text-align: center;
      padding: 40px;
    `;
    lockOverlay.innerHTML = `
      <div style="font-size: 64px; margin-bottom: 24px;">🔒</div>
      <h1 style="font-size: 32px; margin-bottom: 16px;">Exam Locked</h1>
      <p style="font-size: 18px; opacity: 0.8; max-width: 500px; line-height: 1.6;">
        Your exam has been locked due to multiple security violations.
        Please contact your lecturer or administrator to unlock.
      </p>
      <div style="margin-top: 32px; padding: 16px 32px; background: #dc2626; border-radius: 8px;">
        Violations: ${this.violations.length}
      </div>
    `;

    document.body.appendChild(lockOverlay);

    // Notify server
    this.notifyLock();
  }

  async notifyLock() {
    try {
      await fetch(`${this.apiEndpoint}/security/lock/${this.studentId}/${this.examId}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
        },
        body: JSON.stringify({ reason: 'Multiple security violations' }),
      });
    } catch (error) {
      console.error('Failed to notify lock:', error);
    }
  }

  async checkLockStatus() {
    try {
      const response = await fetch(`${this.apiEndpoint}/security/lock-status/${this.examId}`, {
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
        },
      });

      const data = await response.json();
      
      if (data.data?.isLocked && !this.isLocked) {
        this.lockScreen();
      }
    } catch (error) {
      console.error('Failed to check lock status:', error);
    }
  }

  getViolations() {
    return this.violations;
  }

  isScreenLocked() {
    return this.isLocked;
  }
}

// Export for use in exam interface
window.ExamSecurity = ExamSecurity;

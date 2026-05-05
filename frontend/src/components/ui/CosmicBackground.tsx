import { useEffect, useRef } from 'react';

/**
 * CosmicBackground — theme-aware animated background.
 * Dark: deep space with breathing green light orbs.
 * Light: clean white with very subtle green accents.
 */
export default function CosmicBackground({ isDark = true }: { isDark?: boolean }) {
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    let animationId: number;
    let w = 0;
    let h = 0;

    interface Orb {
      baseX: number;
      baseY: number;
      x: number;
      y: number;
      r: number;
      color: [number, number, number];
      baseAlpha: number;
      pulseSpeed: number;
      pulsePhase: number;
      driftAmpX: number;
      driftAmpY: number;
      driftSpeedX: number;
      driftSpeedY: number;
    }
    const orbs: Orb[] = [];

    function resize() {
      w = canvas!.width = window.innerWidth;
      h = canvas!.height = window.innerHeight;
      initOrbs();
    }

    function initOrbs() {
      orbs.length = 0;

      if (isDark) {
        // Dark mode: 4 large green orbs
        const configs = [
          { xR: 0.15, yR: 0.15, r: 320, c: [21, 128, 61] as [number, number, number], a: 0.06, ps: 0.35, dx: 50, dy: 35 },
          { xR: 0.8, yR: 0.2, r: 280, c: [16, 185, 129] as [number, number, number], a: 0.045, ps: 0.45, dx: 70, dy: 40 },
          { xR: 0.45, yR: 0.7, r: 360, c: [34, 197, 94] as [number, number, number], a: 0.05, ps: 0.28, dx: 60, dy: 45 },
          { xR: 0.85, yR: 0.75, r: 240, c: [34, 197, 94] as [number, number, number], a: 0.035, ps: 0.5, dx: 45, dy: 55 },
        ];
        for (const c of configs) {
          orbs.push({
            baseX: w * c.xR, baseY: h * c.yR, x: w * c.xR, y: h * c.yR,
            r: c.r, color: c.c, baseAlpha: c.a, pulseSpeed: c.ps,
            pulsePhase: Math.random() * Math.PI * 2,
            driftAmpX: c.dx, driftAmpY: c.dy,
            driftSpeedX: 0.12 + Math.random() * 0.1,
            driftSpeedY: 0.08 + Math.random() * 0.08,
          });
        }
      } else {
        // Light mode: 3 very subtle green blobs
        const configs = [
          { xR: 0.2, yR: 0.2, r: 400, c: [34, 197, 94] as [number, number, number], a: 0.04, ps: 0.25, dx: 40, dy: 30 },
          { xR: 0.75, yR: 0.3, r: 350, c: [21, 128, 61] as [number, number, number], a: 0.03, ps: 0.35, dx: 55, dy: 35 },
          { xR: 0.5, yR: 0.8, r: 380, c: [34, 197, 94] as [number, number, number], a: 0.025, ps: 0.3, dx: 45, dy: 40 },
        ];
        for (const c of configs) {
          orbs.push({
            baseX: w * c.xR, baseY: h * c.yR, x: w * c.xR, y: h * c.yR,
            r: c.r, color: c.c, baseAlpha: c.a, pulseSpeed: c.ps,
            pulsePhase: Math.random() * Math.PI * 2,
            driftAmpX: c.dx, driftAmpY: c.dy,
            driftSpeedX: 0.1 + Math.random() * 0.08,
            driftSpeedY: 0.07 + Math.random() * 0.06,
          });
        }
      }
    }

    let time = 0;

    function draw() {
      if (!ctx) return;
      time += 0.016;

      ctx.clearRect(0, 0, w, h);

      if (isDark) {
        // --- Dark base gradient ---
        const bg = ctx.createRadialGradient(w * 0.3, h * 0.2, 0, w * 0.5, h * 0.5, Math.max(w, h) * 0.85);
        bg.addColorStop(0, '#0b140f');
        bg.addColorStop(0.4, '#090f0c');
        bg.addColorStop(1, '#050705');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, w, h);
      } else {
        // --- Light base: clean white with faint gradient tint ---
        const bg = ctx.createRadialGradient(w * 0.4, h * 0.25, 0, w * 0.5, h * 0.5, Math.max(w, h) * 0.9);
        bg.addColorStop(0, '#f9fdf9');
        bg.addColorStop(0.5, '#f7fbf7');
        bg.addColorStop(1, '#f5faf5');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, w, h);
      }

      // --- Animated Orbs ---
      for (const orb of orbs) {
        orb.x = orb.baseX + Math.sin(time * orb.driftSpeedX + orb.pulsePhase) * orb.driftAmpX;
        orb.y = orb.baseY + Math.cos(time * orb.driftSpeedY + orb.pulsePhase * 1.3) * orb.driftAmpY;

        const pulse = 0.55 + 0.45 * Math.sin(time * orb.pulseSpeed + orb.pulsePhase);
        const currentAlpha = orb.baseAlpha * pulse;
        const currentR = orb.r * (0.92 + 0.08 * pulse);

        const g = ctx.createRadialGradient(orb.x, orb.y, 0, orb.x, orb.y, currentR);
        g.addColorStop(0, `rgba(${orb.color.join(',')}, ${currentAlpha})`);
        g.addColorStop(0.4, `rgba(${orb.color.join(',')}, ${currentAlpha * 0.4})`);
        g.addColorStop(0.75, `rgba(${orb.color.join(',')}, ${currentAlpha * 0.1})`);
        g.addColorStop(1, `rgba(${orb.color.join(',')}, 0)`);
        ctx.fillStyle = g;
        ctx.fillRect(orb.x - currentR, orb.y - currentR, currentR * 2, currentR * 2);
      }

      if (isDark) {
        // --- Top accent glow (breathing) ---
        const p1 = 0.65 + 0.35 * Math.sin(time * 0.3);
        const g1 = ctx.createRadialGradient(w * 0.1, h * 0.05, 0, w * 0.1, h * 0.05, w * 0.4);
        g1.addColorStop(0, `rgba(21, 128, 61, ${0.06 * p1})`);
        g1.addColorStop(0.5, `rgba(34, 197, 94, ${0.02 * p1})`);
        g1.addColorStop(1, 'rgba(0, 0, 0, 0)');
        ctx.fillStyle = g1;
        ctx.fillRect(0, 0, w, h);
      }

      animationId = requestAnimationFrame(draw);
    }

    resize();
    draw();
    window.addEventListener('resize', resize);

    return () => {
      window.removeEventListener('resize', resize);
      cancelAnimationFrame(animationId);
    };
  }, [isDark]);

  return (
    <canvas
      ref={canvasRef}
      className="fixed inset-0 z-0 pointer-events-none"
    />
  );
}



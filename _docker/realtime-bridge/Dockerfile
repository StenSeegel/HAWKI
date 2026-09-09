FROM python:3.12-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY bridge.py .

# WebRTC media needs UDP on ephemeral ports; run with network_mode: host.
EXPOSE 8089

HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=10s \
    CMD python -c "import urllib.request,os; urllib.request.urlopen(f'http://127.0.0.1:{os.environ.get(\"PORT\", \"8089\")}/health', timeout=3)"

CMD ["python", "bridge.py"]

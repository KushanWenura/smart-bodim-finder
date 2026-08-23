FROM python:3.12-slim
ARG AI_REQUIREMENTS=requirements-fixture.txt
WORKDIR /app
COPY requirements.txt requirements-fixture.txt ./
RUN pip install --no-cache-dir -r "$AI_REQUIREMENTS"
COPY app.py model_runtime.py pipeline_common.py ./
ENV AI_PORT=5100 PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
EXPOSE 5100
CMD ["python", "app.py"]
